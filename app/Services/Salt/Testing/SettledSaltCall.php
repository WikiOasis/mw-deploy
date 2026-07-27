<?php

declare(strict_types=1);

namespace App\Services\Salt\Testing;

use App\Services\Salt\Contracts\PendingSaltCall;
use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;

final class SettledSaltCall implements PendingSaltCall
{
    public function __construct(
        private readonly SaltCall $saltCall,
        private readonly SaltResult $result,
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
        return $this->result;
    }
}
