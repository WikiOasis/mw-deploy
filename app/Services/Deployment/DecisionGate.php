<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Models\Deployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Replaces the curses Prompter's blocking input().
 *
 * The job records a pending decision, the UI renders a modal, and the job polls
 * the row until an operator answers. An unanswered prompt falls back to the
 * configured default once the timeout expires, because leaving the farm parked
 * mid-rollout on a broken ref is worse than either answer.
 */
class DecisionGate
{
    /** @var array<int, Carbon> requestedAt per deployment, for timeout accounting */
    private array $requestedAt = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function request(Deployment $deployment, DecisionReason $reason, array $context): void
    {
        if ($deployment->awaitingDecision() && $deployment->pending_decision === $reason) {
            return;
        }

        $deployment->forceFill([
            'pending_decision' => $reason->value,
            'pending_decision_context' => $context,
            'pending_decision_requested_at' => now(),
            'decision_response' => null,
            'decision_by' => null,
            'decision_answered_at' => null,
        ])->save();

        $this->requestedAt[(int) $deployment->getKey()] = now();

        DeploymentProgressed::dispatch($deployment);
    }

    /**
     * Non-blocking check. Returns null while the prompt is still open.
     */
    public function poll(Deployment $deployment): ?DeploymentDecision
    {
        // Read through the query builder rather than Eloquent: we want the raw
        // persisted column with no casting and no model cache, since the whole
        // point is to observe a write made by a different process (the browser).
        $answer = DB::table('deployments')
            ->where('id', $deployment->getKey())
            ->value('decision_response');

        if (is_string($answer) && $answer !== '') {
            $deployment->refresh();

            return DeploymentDecision::from($answer);
        }

        if ($this->timedOut($deployment)) {
            $default = $this->timeoutDefault();

            $this->record($deployment, $default, null);

            return $default;
        }

        return null;
    }

    /**
     * Blocking wait, used by the sequential staging phase.
     *
     * Also resolves on a manual abort request — an operator hitting "abort" does
     * not need to know a canary prompt happens to be open right now for it to work.
     *
     * @param  array<string, mixed>  $context
     */
    public function await(Deployment $deployment, DecisionReason $reason, array $context): DeploymentDecision
    {
        $this->request($deployment, $reason, $context);

        $interval = max(1, (int) config('mwdeploy.decisions.poll_interval', 2));

        while (true) {
            $decision = $this->poll($deployment);

            if ($decision !== null) {
                return $decision;
            }

            $rollback = $this->abortRequested($deployment);

            if ($rollback !== null) {
                return $rollback ? DeploymentDecision::AbortAndRollback : DeploymentDecision::Abort;
            }

            $this->sleep($interval);
        }
    }

    /**
     * Record an operator's out-of-band "stop this deployment" request. Unlike
     * request()/record(), this is not tied to a specific prompt the job raised —
     * it can land at any point while the deployment is running, and the runner
     * checks for it at its own checkpoints (between steps, between servers)
     * rather than only inside a blocking prompt.
     *
     * Guarded to only apply while the deployment is still running, so a request
     * that arrives after the deployment has already finished on its own is a
     * harmless no-op rather than a stray write.
     */
    public function requestAbort(Deployment $deployment, bool $rollback, ?int $userId): bool
    {
        try {
            $updated = DB::table('deployments')
                ->where('id', $deployment->getKey())
                ->where('status', DeploymentStatus::Running->value)
                ->update([
                    'abort_requested_at' => now(),
                    'abort_requested_by' => $userId,
                    'abort_rollback' => $rollback,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('mwdeploy: could not record a manual abort request', [
                'deployment' => $deployment->getKey(),
                'exception' => $exception,
            ]);

            return false;
        }

        if ($updated > 0) {
            DeploymentProgressed::dispatch($deployment->fresh());
        }

        return $updated > 0;
    }

    /**
     * Non-blocking check for a manual abort request, read straight from the
     * database for the same reason poll() does: the runner's in-memory model was
     * loaded before the browser's request landed.
     *
     * Checked at every checkpoint of every deployment, unlike poll() which only
     * runs while something has already gone wrong — so a failure here (a
     * migration not yet applied in this environment, a transient DB error) must
     * degrade to "nothing requested" rather than crash a deployment that has
     * nothing to do with aborting. This is the one query on the happy path of
     * every deployment that this class did not have before; it must not be the
     * one that takes the whole thing down.
     *
     * @return bool|null null when nothing has been requested, otherwise whether a
     *                   rollback was asked for alongside the abort
     */
    public function abortRequested(Deployment $deployment): ?bool
    {
        // The property access is inside the try along with the query itself: a
        // schema missing these columns can return a row that simply lacks the
        // property rather than raising a query exception, and either failure mode
        // must degrade the same way.
        try {
            $row = DB::table('deployments')
                ->where('id', $deployment->getKey())
                ->select('abort_requested_at', 'abort_rollback')
                ->first();

            if ($row === null || $row->abort_requested_at === null) {
                return null;
            }

            return (bool) $row->abort_rollback;
        } catch (Throwable $exception) {
            Log::warning('mwdeploy: could not check for a manual abort request, assuming none', [
                'deployment' => $deployment->getKey(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * Persist an operator's answer. Called from the controller as well as from
     * the timeout path, which passes a null user.
     */
    public function record(Deployment $deployment, DeploymentDecision $decision, ?int $userId): void
    {
        $deployment->forceFill([
            'decision_response' => $decision->value,
            'decision_by' => $userId,
            'decision_answered_at' => now(),
        ])->save();

        DeploymentProgressed::dispatch($deployment);
    }

    /**
     * Close the prompt out so a later canary failure can open a fresh one.
     */
    public function clear(Deployment $deployment): void
    {
        $deployment->forceFill([
            'pending_decision' => null,
            'pending_decision_context' => null,
            'pending_decision_requested_at' => null,
            'decision_response' => null,
            'decision_by' => null,
            'decision_answered_at' => null,
        ])->save();

        unset($this->requestedAt[(int) $deployment->getKey()]);

        DeploymentProgressed::dispatch($deployment);
    }

    public function timeoutDefault(): DeploymentDecision
    {
        $configured = (string) config('mwdeploy.decisions.timeout_default', 'abort_and_rollback');

        return DeploymentDecision::tryFrom($configured) ?? DeploymentDecision::AbortAndRollback;
    }

    private function timedOut(Deployment $deployment): bool
    {
        $timeout = (int) config('mwdeploy.decisions.timeout', 900);

        if ($timeout <= 0) {
            return false;
        }

        $requestedAt = $this->requestedAt[(int) $deployment->getKey()]
            ?? $deployment->pending_decision_requested_at;

        if ($requestedAt === null) {
            return false;
        }

        return $requestedAt->clone()->addSeconds($timeout)->isPast();
    }

    /**
     * Extracted so tests can drive the loop without real sleeping.
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
