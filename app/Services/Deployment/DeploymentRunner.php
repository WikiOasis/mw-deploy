<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Actions\Deployments\RollbackDeployment;
use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepStatus;
use App\Enums\TargetRole;
use App\Events\DeploymentProgressed;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeploymentStep;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The orchestration from section 5 of the handoff spec. Laravel is the brain
 * here: it sequences, retries, aborts and records. Salt is a dumb remote-exec
 * transport, one call per step per server.
 */
final class DeploymentRunner
{
    private StepRecorder $recorder;

    private DeploymentOptions $options;

    /** True once at least one staging checkout has changed a working tree. */
    private bool $stagingMutated = false;

    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
        private readonly DecisionGate $decisions,
        private readonly RollbackDeployment $rollback,
    ) {}

    public function run(Deployment $deployment): void
    {
        $this->recorder = new StepRecorder($deployment);
        $this->options = $deployment->opts();
        $this->stagingMutated = false;

        $deployment->loadMissing(['repoRefs.repository', 'deploymentPatches.patch']);

        $this->markRunning($deployment);

        try {
            $this->execute($deployment);
        } catch (Throwable $exception) {
            Log::error('mwdeploy: deployment crashed', [
                'deployment' => $deployment->getKey(),
                'exception' => $exception,
            ]);

            $this->finish($deployment, DeploymentStatus::Failed, 'Deployment crashed: '.$exception->getMessage());

            throw $exception;
        }
    }

    private function execute(Deployment $deployment): void
    {
        $refs = $deployment->repoRefs;

        // 2. Record the undo point, then check out the requested refs.
        if (! $this->snapshotAndCheckout($deployment, $refs)) {
            return;
        }

        // 3. Patches, in registry order, against the freshly-checked-out tree.
        if (! $this->applyPatches($deployment)) {
            return;
        }

        $syncPaths = $this->calls->requiresFullTreeSync($refs, $this->options)
            ? []
            : $this->calls->relativePathsFor($refs);

        // 4. Staging → production on the staging host.
        if (! $this->runStagingStep($deployment, $this->calls->rsyncLocal($syncPaths))) {
            return;
        }

        // 5. l10n cache rebuild on staging.
        if ($this->options->l10n
            && ! $this->runStagingStep($deployment, $this->calls->l10nRebuild($this->calls->stagingTarget()))) {
            return;
        }

        // 6. Staging canary — the gate that blocks the fleet rollout.
        if (! $this->stagingCanary($deployment)) {
            return;
        }

        // 7. Per-server rollout.
        if ($this->options->stagingOnly) {
            $this->finish($deployment, DeploymentStatus::Done, null);

            return;
        }

        $this->rollout($deployment, $syncPaths);
    }

    /**
     * @param  Collection<int, DeploymentRepoRef>  $refs
     */
    private function snapshotAndCheckout(Deployment $deployment, Collection $refs): bool
    {
        // Read every HEAD first, before anything mutates staging: a snapshot
        // taken after the first checkout would record the new state as the undo
        // point for the repos that follow.
        foreach ($refs as $ref) {
            $this->snapshot($deployment, $ref);
        }

        foreach ($refs as $ref) {
            $call = $this->calls->gitCheckout($ref);
            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            if ($result->ok) {
                $this->stagingMutated = true;

                continue;
            }

            // --force covers a failed checkout too: the operator has said they
            // want the deploy to proceed on whatever is on disk.
            if ($this->options->force) {
                $this->recorder->note($step, 'continuing despite failure because --force is set');

                continue;
            }

            $this->abort(
                $deployment,
                sprintf('git checkout failed for %s: %s', $ref->repository->displayName(), $result->detail()),
            );

            return false;
        }

        return true;
    }

    /**
     * Read the repo's current HEAD on staging and store it as this deployment's
     * undo point.
     */
    private function snapshot(Deployment $deployment, DeploymentRepoRef $ref): void
    {
        $call = $this->calls->gitHead($ref->repository);
        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        $previousValue = $result->ok ? $result->payloadValue('ref') : null;
        $previousType = $result->ok ? $result->payloadValue('ref_type') : null;

        if (! $result->ok) {
            $this->recorder->note(
                $step,
                'no undo point recorded for '.$ref->repository->displayName().'; rollback will skip this repo',
            );
        }

        $deployment->snapshots()->updateOrCreate(
            ['repository_id' => $ref->repository_id],
            [
                'previous_ref_type' => is_string($previousType)
                    ? (RefType::tryFrom($previousType)?->value ?? RefType::Commit->value)
                    : (is_string($previousValue) ? RefType::detect($previousValue)->value : null),
                'previous_ref_value' => is_string($previousValue) && $previousValue !== '' ? $previousValue : null,
                'new_ref_type' => $ref->ref_type->value,
                'new_ref_value' => $ref->ref_value,
            ],
        );
    }

    private function applyPatches(Deployment $deployment): bool
    {
        foreach ($deployment->deploymentPatches as $deploymentPatch) {
            /** @var Patch|null $patch */
            $patch = $deploymentPatch->patch;

            if ($patch === null) {
                continue;
            }

            $call = $this->calls->patchApply($patch);
            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            $deploymentPatch->update([
                'applied' => $result->ok,
                'applied_to_ref' => $this->refAppliedAgainst($deployment, $patch),
            ]);

            if ($result->ok || $this->options->force) {
                continue;
            }

            // A patch written against an older commit failing to apply is a
            // normal step failure, not a special case.
            $this->abort($deployment, sprintf('patch "%s" failed to apply: %s', $patch->name, $result->detail()));

            return false;
        }

        return true;
    }

    private function refAppliedAgainst(Deployment $deployment, Patch $patch): ?string
    {
        if ($patch->target_repo_id === null) {
            return null;
        }

        return $deployment->repoRefs
            ->firstWhere('repository_id', $patch->target_repo_id)
            ?->ref_value;
    }

    private function runStagingStep(Deployment $deployment, SaltCall $call): bool
    {
        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        if ($result->ok) {
            return true;
        }

        if ($this->options->force) {
            $this->recorder->note($step, 'continuing despite failure because --force is set');

            return true;
        }

        $this->abort($deployment, $call->step()->label().' failed on staging: '.$result->detail());

        return false;
    }

    /**
     * Step 6. On failure without --force this blocks on an operator decision,
     * exactly as the curses Prompter did, and defaults to abort-and-roll-back if
     * nobody answers.
     */
    private function stagingCanary(Deployment $deployment): bool
    {
        $call = $this->calls->canary($this->calls->stagingTarget());
        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        if ($result->ok) {
            return true;
        }

        if ($this->options->force) {
            $this->recorder->note($step, 'canary failed but --force is set; continuing to rollout');

            return true;
        }

        $decision = $this->decisions->await($deployment, DecisionReason::StagingCanaryFailed, [
            'host' => $this->calls->stagingTarget(),
            'vhost' => (string) config('mwdeploy.rollout.canary_vhost'),
            'detail' => $result->detail(),
        ]);

        $this->decisions->clear($deployment);
        $this->recorder->note($step, 'operator decision: '.$decision->value);

        if ($decision === DeploymentDecision::Continue) {
            return true;
        }

        $this->abort(
            $deployment,
            'staging canary failed: '.$result->detail(),
            rollback: $decision === DeploymentDecision::AbortAndRollback,
        );

        return false;
    }

    /**
     * @param  list<string>  $syncPaths
     */
    private function rollout(Deployment $deployment, array $syncPaths): void
    {
        $servers = $this->appservers($deployment);

        if ($servers->isEmpty()) {
            $this->finish($deployment, DeploymentStatus::Failed, 'No active appservers matched this deployment.');

            return;
        }

        $pool = new RolloutPool(
            salt: $this->salt,
            calls: $this->calls,
            recorder: $this->recorder,
            decisions: $this->decisions,
            deployment: $deployment,
            servers: $servers,
            proxies: $this->proxies(),
            options: $this->options,
            syncPaths: $syncPaths,
        );

        $results = $pool->run();

        $failed = array_keys(array_filter($results, fn (bool $ok) => ! $ok));

        if ($pool->abortRequested()) {
            $this->abort(
                $deployment,
                'aborted during rollout after a canary failure on: '.implode(', ', $failed),
                rollback: $pool->rollbackRequested(),
                status: DeploymentStatus::Aborted,
            );

            return;
        }

        if ($failed !== []) {
            $this->finish(
                $deployment,
                DeploymentStatus::Failed,
                'Rollout failed on: '.implode(', ', $failed),
            );

            return;
        }

        $this->finish($deployment, DeploymentStatus::Done, null);
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function appservers(Deployment $deployment): Collection
    {
        $query = DeployTarget::query()
            ->active()
            ->role(TargetRole::Appserver)
            ->orderBy('sort_order')
            ->orderBy('hostname');

        // An empty server list means "all", matching `--servers all`.
        if ($this->options->servers !== []) {
            $query->whereIn('hostname', $this->options->servers);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function proxies(): Collection
    {
        return DeployTarget::query()
            ->active()
            ->role(TargetRole::Proxy)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();
    }

    private function markRunning(Deployment $deployment): void
    {
        $deployment->forceFill([
            'status' => DeploymentStatus::Running->value,
            'started_at' => $deployment->started_at ?? now(),
            'failure_reason' => null,
        ])->save();

        DeploymentProgressed::dispatch($deployment);
    }

    /**
     * Stop the deployment, optionally enqueueing the automatic rollback.
     */
    private function abort(
        Deployment $deployment,
        string $reason,
        bool $rollback = true,
        DeploymentStatus $status = DeploymentStatus::Failed,
    ): void {
        $this->finish($deployment, $status, $reason, autoRollback: $rollback);
    }

    private function finish(
        Deployment $deployment,
        DeploymentStatus $status,
        ?string $reason,
        bool $autoRollback = false,
    ): void {
        $deployment->forceFill([
            'status' => $status->value,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ])->save();

        $this->markPendingStepsSkipped($deployment, $status);

        // A successful rollback retires the steps it undid, so history shows
        // which work was reverted rather than leaving it green.
        if ($status === DeploymentStatus::Done && $deployment->isRollback()) {
            $this->retireRolledBackSteps($deployment);
        }

        DeploymentProgressed::dispatch($deployment);

        if (! $autoRollback || $status === DeploymentStatus::Done) {
            return;
        }

        $this->maybeAutoRollback($deployment, $reason);
    }

    private function maybeAutoRollback(Deployment $deployment, ?string $reason): void
    {
        // Cap automatic rollback at one hop: auto-rolling-back a rollback is how
        // one bad deploy becomes an outage.
        if ($deployment->isRollback()) {
            $deployment->forceFill([
                'failure_reason' => trim(($reason ?? '').' — this deployment is itself a rollback, so no automatic '
                    .'rollback was enqueued. Manual intervention required.'),
            ])->save();

            DeploymentProgressed::dispatch($deployment);

            return;
        }

        if ($this->options->force) {
            return;
        }

        // Nothing was changed on staging, so there is nothing to undo.
        if (! $this->stagingMutated) {
            return;
        }

        $rollback = ($this->rollback)(
            failed: $deployment,
            actor: null,
            servers: $deployment->touchedAppservers(),
        );

        if ($rollback === null) {
            $deployment->forceFill([
                'failure_reason' => trim(($reason ?? '').' — no usable undo point was recorded, so no automatic '
                    .'rollback could be enqueued.'),
            ])->save();

            DeploymentProgressed::dispatch($deployment);
        }
    }

    private function markPendingStepsSkipped(Deployment $deployment, DeploymentStatus $status): void
    {
        if ($status === DeploymentStatus::Done) {
            return;
        }

        $deployment->steps()
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->get()
            ->each(function (DeploymentStep $step) {
                $step->status = StepStatus::Skipped;
                $step->finished_at = now();
                $step->log = trim(($step->log ?? '')."\nskipped: deployment ended");
                $step->save();
            });
    }

    private function retireRolledBackSteps(Deployment $rollbackDeployment): void
    {
        $original = $rollbackDeployment->rollsBack;

        if ($original === null) {
            return;
        }

        $hosts = $rollbackDeployment->steps()->distinct()->pluck('target_hostname');

        $original->steps()
            ->whereIn('target_hostname', $hosts)
            ->where('status', StepStatus::Done->value)
            ->update(['status' => StepStatus::RolledBack->value]);
    }
}
