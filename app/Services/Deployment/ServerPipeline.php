<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\StepName;
use App\Models\DeployTarget;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Generator;
use Illuminate\Support\Collection;

/**
 * The per-appserver half of a deployment, expressed as a generator that yields
 * the next Salt call and receives its outcome.
 *
 * Modelling it this way is what lets the pool hold --parallel N servers in flight
 * inside one queued job: each pipeline only ever has one call outstanding, and
 * the pool decides when to advance it.
 */
final class ServerPipeline
{
    /**
     * @param  Collection<int, DeployTarget>  $proxies
     */
    public function __construct(
        private readonly ShimCalls $calls,
        private readonly DeployTarget $server,
        private readonly Collection $proxies,
        private readonly DeploymentOptions $options,
        private readonly SyncPlan $syncPlan,
        private readonly RemovalPlan $removals,
    ) {}

    public function server(): DeployTarget
    {
        return $this->server;
    }

    /**
     * @return Generator<int, SaltCall, StepOutcome, bool> true when the server
     *                                                     finished cleanly
     */
    public function run(): Generator
    {
        /** @var list<DeployTarget> $depooled */
        $depooled = [];
        $failed = false;

        // Depool from every proxy before touching the box.
        if ($this->options->rollout) {
            foreach ($this->proxies as $proxy) {
                $outcome = yield $this->calls->haproxy(StepName::HaproxyDepool, $proxy, $this->server);

                if (! $outcome->proceed) {
                    // Refuse to touch a server that is still taking traffic on
                    // some proxy; repool whatever we did manage to depool.
                    yield from $this->repool($depooled);

                    return false;
                }

                $depooled[] = $proxy;
            }
        }

        // Removals first: a checkout that is going away should not be rsynced
        // one step before it is deleted.
        foreach ($this->removals->callsFor($this->server->hostname) as $removal) {
            $outcome = yield $removal;

            if (! $outcome->proceed) {
                $failed = true;
                break;
            }
        }

        // Get the bits onto the box. Skipped entirely when every action was a
        // removal — there is nothing to sync, and syncing "no paths" would mean
        // walking the whole tree.
        if (! $failed && $this->syncPlan->required) {
            $outcome = yield $this->calls->rsyncRemote($this->server, $this->syncPlan);
            $failed = ! $outcome->proceed;
        }

        if (! $failed && $this->options->l10n) {
            $outcome = yield $this->calls->l10nRebuild($this->server->hostname);
            $failed = ! $outcome->proceed;
        }

        // The pool converts a canary failure into a blocking prompt (or honours
        // --force) before deciding what to send back.
        if (! $failed) {
            $outcome = yield $this->calls->canary($this->server->hostname, $this->server->canaryVhost());
            $failed = ! $outcome->proceed;
        }

        // Repool regardless of outcome: a depooled server left out of the pool is
        // its own outage.
        yield from $this->repool($depooled);

        return ! $failed;
    }

    /**
     * @param  list<DeployTarget>  $depooled
     * @return Generator<int, SaltCall, StepOutcome, void>
     */
    private function repool(array $depooled): Generator
    {
        foreach ($depooled as $proxy) {
            // Deliberately ignoring the outcome: there is nothing useful to do
            // with a failed repool beyond recording it loudly, which the step row
            // already does.
            yield $this->calls->haproxy(StepName::HaproxyRepool, $proxy, $this->server);
        }
    }
}
