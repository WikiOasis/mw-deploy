<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\DeploymentStep;
use App\Models\DeployTarget;
use App\Services\Salt\Contracts\PendingSaltCall;
use App\Services\Salt\SaltCall;
use Generator;

/**
 * Mutable bookkeeping for one in-flight server pipeline. Exactly one Salt call
 * is outstanding per slot at any moment, which is what keeps the pool's
 * concurrency accounting honest.
 */
final class PipelineSlot
{
    public ?PendingSaltCall $pending = null;

    /** The step row for the outstanding call. */
    public ?DeploymentStep $currentStep = null;

    /** Set when this pipeline is parked on a blocking canary prompt. */
    public ?StepOutcome $awaitingDecision = null;

    /**
     * @param  Generator<int, SaltCall, StepOutcome, bool>  $generator
     */
    public function __construct(
        public readonly DeployTarget $server,
        public readonly Generator $generator,
    ) {}

    public function isBusy(): bool
    {
        return $this->pending !== null || $this->awaitingDecision !== null;
    }
}
