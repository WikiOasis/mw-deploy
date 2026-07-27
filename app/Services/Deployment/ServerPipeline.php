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
 * Step 7 of the orchestration spec for a single appserver, expressed as a
 * generator that yields the next Salt call and receives its outcome.
 *
 * Modelling it this way is what lets the pool hold --parallel N servers in
 * flight inside one queued job: each pipeline only ever has one call
 * outstanding, and the pool decides when to advance it.
 */
final class ServerPipeline
{
    /**
     * @param  Collection<int, DeployTarget>  $proxies
     * @param  list<string>  $syncPaths
     */
    public function __construct(
        private readonly ShimCalls $calls,
        private readonly DeployTarget $server,
        private readonly Collection $proxies,
        private readonly DeploymentOptions $options,
        private readonly array $syncPaths,
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

        // 7a — depool from every proxy before touching the box.
        if ($this->options->rollout) {
            foreach ($this->proxies as $proxy) {
                $outcome = yield $this->calls->haproxy(StepName::HaproxyDepool, $proxy, $this->server);

                if (! $outcome->proceed) {
                    // Refuse to rsync a server that is still taking traffic on
                    // some proxy; repool whatever we did manage to depool.
                    yield from $this->repool($depooled);

                    return false;
                }

                $depooled[] = $proxy;
            }
        }

        // 7b — get the bits onto the box.
        $outcome = yield $this->calls->rsyncRemote($this->server, $this->syncPaths);
        $failed = ! $outcome->proceed;

        // 7c — l10n cache rebuild.
        if (! $failed && $this->options->l10n) {
            $outcome = yield $this->calls->l10nRebuild($this->server->hostname);
            $failed = ! $outcome->proceed;
        }

        // 7d — canary. The pool converts a failure here into a blocking prompt
        // (or honours --force) before deciding what to send back.
        if (! $failed) {
            $outcome = yield $this->calls->canary($this->server->hostname, $this->server->canaryVhost());
            $failed = ! $outcome->proceed;
        }

        // 7e — repool regardless of outcome: a depooled server left out of the
        // pool is its own outage.
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
            // with a failed repool beyond recording it loudly, which the step
            // row already does.
            yield $this->calls->haproxy(StepName::HaproxyRepool, $proxy, $this->server);
        }
    }
}
