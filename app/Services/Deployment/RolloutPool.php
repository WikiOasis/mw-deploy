<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\StepName;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Collection;

/**
 * Bounded-concurrency driver for step 7, matching the original script's
 * ThreadPoolExecutor(max_workers=parallel) behaviour without threads: at most N
 * server pipelines have a Salt subprocess in flight at once, and the pool
 * advances whichever one settles first.
 *
 * A canary failure parks only the pipeline that hit it; the other pipelines keep
 * moving while the operator decides, which the curses tool could not do.
 */
final class RolloutPool
{
    /** @var array<string, PipelineSlot> */
    private array $slots = [];

    /** @var array<string, bool> hostname => finished cleanly */
    private array $results = [];

    private bool $aborted = false;

    private bool $rollbackRequested = false;

    private bool $abortedManually = false;

    /**
     * @param  Collection<int, DeployTarget>  $servers
     * @param  Collection<int, DeployTarget>  $proxies
     */
    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
        private readonly StepRecorder $recorder,
        private readonly DecisionGate $decisions,
        private readonly Deployment $deployment,
        private readonly Collection $servers,
        private readonly Collection $proxies,
        private readonly DeploymentOptions $options,
        private readonly SyncPlan $syncPlan,
        private readonly RemovalPlan $removals,
    ) {}

    /**
     * @return array<string, bool> hostname => success
     */
    public function run(): array
    {
        /** @var list<DeployTarget> $queue */
        $queue = $this->servers->values()->all();

        $limit = max(1, min(
            $this->options->parallel,
            max(1, (int) config('mwdeploy.rollout.max_parallel', 8)),
        ));

        while ($queue !== [] || $this->slots !== []) {
            $this->checkManualAbort();

            while (! $this->aborted && $queue !== [] && count($this->slots) < $limit) {
                $this->open(array_shift($queue));
            }

            if ($this->aborted && $queue !== []) {
                $queue = $this->drain($queue);
            }

            if ($this->slots === []) {
                continue;
            }

            if (! $this->tick()) {
                $this->idle();
            }
        }

        return $this->results;
    }

    public function abortRequested(): bool
    {
        return $this->aborted;
    }

    /**
     * An operator's "abort" click is not tied to any one slot's canary prompt, so
     * it is checked once per pass rather than inside resolveDecision(). Reuses the
     * exact same aborted/rollbackRequested flags a canary-triggered abort sets, so
     * drain() and advance() need no separate manual-abort code path.
     */
    private function checkManualAbort(): void
    {
        if ($this->aborted) {
            return;
        }

        $rollback = $this->decisions->abortRequested($this->deployment);

        if ($rollback === null) {
            return;
        }

        $this->aborted = true;
        $this->rollbackRequested = $rollback;
        $this->abortedManually = true;
    }

    public function rollbackRequested(): bool
    {
        return $this->rollbackRequested;
    }

    /**
     * Whether the abort came from an operator's manual request rather than a
     * canary failure — the runner uses this to write an accurate reason instead
     * of always blaming "a canary failure".
     */
    public function abortedManually(): bool
    {
        return $this->abortedManually;
    }

    /**
     * Servers the abort means we never reach. Recording a skipped step for each
     * is the difference between "we chose not to touch this box" and "we forgot".
     *
     * @param  list<DeployTarget>  $queue
     * @return list<DeployTarget>
     */
    private function drain(array $queue): array
    {
        foreach ($queue as $server) {
            $this->recorder->skip(
                $this->skippedStepFor($server),
                'deployment aborted before this server was reached',
            );

            $this->results[$server->hostname] = false;
        }

        return [];
    }

    /**
     * A representative call for a server we never reached, so the skipped step
     * row says something meaningful. For a removal-only deployment the rsync call
     * would be a lie, since no rsync was ever going to run.
     */
    private function skippedStepFor(DeployTarget $server): SaltCall
    {
        if (! $this->syncPlan->required && ! $this->removals->isEmpty()) {
            return $this->removals->callsFor($server->hostname)[0];
        }

        return $this->calls->rsyncRemote($server, $this->syncPlan);
    }

    private function open(DeployTarget $server): void
    {
        $pipeline = new ServerPipeline(
            $this->calls,
            $server,
            $this->proxies,
            $this->options,
            $this->syncPlan,
            $this->removals,
        );

        $slot = new PipelineSlot($server, $pipeline->run());
        $this->slots[$server->hostname] = $slot;

        $this->advance($slot);
    }

    /**
     * One pass over every slot. Returns true if anything moved, so the caller
     * knows whether to sleep before trying again.
     */
    private function tick(): bool
    {
        $progressed = false;

        // foreach iterates a copy, so closing a slot mid-loop is safe.
        foreach ($this->slots as $slot) {
            if ($slot->awaitingDecision !== null) {
                $progressed = $this->resolveDecision($slot) || $progressed;

                continue;
            }

            if ($slot->pending === null || ! $slot->pending->isFinished()) {
                continue;
            }

            $this->settle($slot);
            $progressed = true;
        }

        return $progressed;
    }

    /**
     * A Salt call for this slot has exited: record it, then decide what to send
     * back into the pipeline.
     */
    private function settle(PipelineSlot $slot): void
    {
        $pending = $slot->pending;
        $step = $slot->currentStep;

        $slot->pending = null;
        $slot->currentStep = null;

        if ($pending === null) {
            return;
        }

        $call = $pending->call();
        $result = $pending->wait();

        if ($step !== null) {
            $this->recorder->finish($step, $result);
        }

        if ($result->ok) {
            $this->send($slot, StepOutcome::fromResult($call, $result));

            return;
        }

        // Everything except a canary failure is a plain step failure.
        if ($call->step() !== StepName::Canary) {
            $this->send($slot, StepOutcome::halted($call, $result));

            return;
        }

        if ($this->options->force) {
            $this->send($slot, StepOutcome::overridden($call, $result));

            return;
        }

        // Park this pipeline on a blocking prompt. Other slots keep running.
        $this->decisions->request($this->deployment, DecisionReason::ServerCanaryFailed, [
            'host' => $slot->server->hostname,
            'vhost' => $slot->server->canaryVhost(),
            'detail' => $result->detail(),
        ]);

        $slot->awaitingDecision = StepOutcome::halted($call, $result);
    }

    /**
     * Poll the parked prompt for this slot; returns true if it resolved.
     */
    private function resolveDecision(PipelineSlot $slot): bool
    {
        $outcome = $slot->awaitingDecision;

        if ($outcome === null) {
            return false;
        }

        $decision = $this->decisions->poll($this->deployment);

        if ($decision === null) {
            return false;
        }

        $slot->awaitingDecision = null;
        $this->decisions->clear($this->deployment);

        if ($decision === DeploymentDecision::Continue) {
            $this->send($slot, StepOutcome::overridden($outcome->call, $outcome->result));

            return true;
        }

        $this->aborted = true;
        $this->rollbackRequested = $decision === DeploymentDecision::AbortAndRollback;

        $this->send($slot, $outcome);

        return true;
    }

    private function send(PipelineSlot $slot, StepOutcome $outcome): void
    {
        $slot->generator->send($outcome);

        $this->advance($slot);
    }

    /**
     * Start the pipeline's next call, or close the slot if it is finished.
     *
     * When the deployment has been aborted the generator still runs to
     * completion: its remaining yields are the repool steps, and skipping those
     * would leave a depooled server out of the pool.
     */
    private function advance(PipelineSlot $slot): void
    {
        while ($slot->generator->valid()) {
            /** @var SaltCall $call */
            $call = $slot->generator->current();

            if ($this->aborted && ! $this->isCleanupStep($call)) {
                $this->recorder->skip($call, 'deployment aborted');
                $slot->generator->send(StepOutcome::halted($call, SaltResult::aborted($call->target)));

                continue;
            }

            $slot->currentStep = $this->recorder->begin($call);
            $slot->pending = $this->salt->start($call);

            return;
        }

        $this->close($slot);
    }

    private function isCleanupStep(SaltCall $call): bool
    {
        return $call->step() === StepName::HaproxyRepool;
    }

    private function close(PipelineSlot $slot): void
    {
        $this->results[$slot->server->hostname] = (bool) $slot->generator->getReturn();

        unset($this->slots[$slot->server->hostname]);
    }

    /**
     * Extracted so tests can drive the loop without real sleeping.
     */
    protected function idle(): void
    {
        usleep(200_000);
    }
}
