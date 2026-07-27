<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Services\Salt\SaltCall;

final readonly class PlannedCall
{
    public function __construct(
        public string $phase,
        public SaltCall $call,
    ) {}

    public function target(): string
    {
        return $this->call->target;
    }

    public function label(): string
    {
        $label = $this->call->step()->label();

        return $this->call->subject === null ? $label : $label.': '.$this->call->subject;
    }

    public function commandLine(): string
    {
        return $this->call->describe();
    }
}
