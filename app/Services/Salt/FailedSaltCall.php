<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Services\Salt\Contracts\PendingSaltCall;

/**
 * A call that never made it as far as a subprocess (e.g. the salt binary is
 * missing). Modelled as an already-finished pending call so the rollout pool
 * does not need a separate error path.
 */
final class FailedSaltCall implements PendingSaltCall
{
    public function __construct(
        private readonly SaltCall $saltCall,
        private readonly string $error,
    ) {}

    public function call(): SaltCall
    {
        return $this->saltCall;
    }

    public function isFinished(): bool
    {
        return true;
    }

    public function wait(): SaltResult
    {
        return new SaltResult(
            ok: false,
            target: $this->saltCall->target,
            retcode: null,
            error: $this->error,
        );
    }
}
