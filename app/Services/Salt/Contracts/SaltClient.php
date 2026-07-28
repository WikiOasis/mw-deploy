<?php

declare(strict_types=1);

namespace App\Services\Salt\Contracts;

use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;

interface SaltClient
{
    /**
     * Run a Salt call and block until the local `salt` subprocess exits.
     */
    public function run(SaltCall $call): SaltResult;

    /**
     * Start a Salt call without waiting. Used by the rollout pool to hold up to
     * --parallel N server pipelines in flight inside a single queued job.
     */
    public function start(SaltCall $call): PendingSaltCall;

    /**
     * Start a call with `salt --async` and return its job ID as soon as the
     * master has accepted it. Fast: unlike start(), this never waits on a
     * minion, only on Salt handing back a JID — so it is safe to call inline in
     * an HTTP request even when the call itself may run for the better part of
     * an hour on the minion.
     */
    public function startAsync(SaltCall $call): string;

    /**
     * Check on a job started with startAsync(). Null means no minion has
     * reported back yet — not a failure, just "still running" — so the caller
     * polls again later rather than treating it as an error.
     */
    public function lookupJid(string $jid, string $target): ?SaltResult;
}
