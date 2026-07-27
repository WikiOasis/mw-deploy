<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Models\Deployment;
use App\Services\Deployment\DecisionGate;
use Illuminate\Support\Carbon;

/**
 * Stands in for an operator watching the dashboard.
 *
 * A canary failure genuinely blocks the job until the database row changes, so a
 * test cannot simply pre-write the answer: request() deliberately clears any
 * previous response so a second failure gets a fresh prompt. This gate answers
 * the moment the prompt is raised, which is exactly what a human does.
 *
 * With no answer configured it leaves the prompt open, and its sleep() advances
 * the test clock so the real timeout path can be exercised without waiting.
 */
final class AutoAnsweringDecisionGate extends DecisionGate
{
    private ?DeploymentDecision $answer = null;

    /** @var list<array{reason: DecisionReason, context: array<string, mixed>}> */
    public array $prompts = [];

    public function answerWith(?DeploymentDecision $decision): self
    {
        $this->answer = $decision;

        return $this;
    }

    public function request(Deployment $deployment, DecisionReason $reason, array $context): void
    {
        parent::request($deployment, $reason, $context);

        $this->prompts[] = ['reason' => $reason, 'context' => $context];

        if ($this->answer !== null) {
            $this->record($deployment, $this->answer, null);
        }
    }

    public function promptCount(): int
    {
        return count($this->prompts);
    }

    /**
     * Advance the frozen test clock instead of blocking, so timeout accounting
     * still works while the suite stays fast.
     */
    protected function sleep(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(max(1, $seconds)));
    }
}
