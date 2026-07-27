<?php

declare(strict_types=1);

namespace App\Services\Salt\Contracts;

use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;

interface PendingSaltCall
{
    public function call(): SaltCall;

    /**
     * True once the underlying subprocess has exited. Non-blocking.
     */
    public function isFinished(): bool;

    /**
     * Block until finished and return the parsed result. Safe to call twice.
     */
    public function wait(): SaltResult;
}
