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
}
