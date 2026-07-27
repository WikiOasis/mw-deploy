<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;

/**
 * What the rollout pool sends back into a server pipeline after a Salt call
 * settles.
 *
 * `proceed` is not just `$result->ok`: a canary failure that an operator chose
 * to continue through (or that --force covered) is a failed result the pipeline
 * is nonetheless told to carry on from.
 */
final readonly class StepOutcome
{
    public function __construct(
        public SaltCall $call,
        public SaltResult $result,
        public bool $proceed,
        public bool $overridden = false,
    ) {}

    public static function fromResult(SaltCall $call, SaltResult $result): self
    {
        return new self($call, $result, $result->ok);
    }

    public static function overridden(SaltCall $call, SaltResult $result): self
    {
        return new self($call, $result, true, true);
    }

    public static function halted(SaltCall $call, SaltResult $result): self
    {
        return new self($call, $result, false);
    }
}
