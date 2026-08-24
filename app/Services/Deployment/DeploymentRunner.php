<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Actions\Deployments\RollbackDeployment;
use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Enums\StepStatus;
use App\Enums\TargetRole;
use App\Events\DeploymentProgressed;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeploymentStep;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The orchestrator. Laravel is the brain here: it sequences, retries, aborts and
 * records. Salt is a dumb remote-exec transport, one call per step per server.
 *
 * Every intent — deploy, undeploy, sync the staging tree as it stands, create a
 * core version, remove a core version, roll any of those back — runs through this
 * one pipeline. What differs between them is the line items in
 * deployment_repo_refs, not the control flow: a staging sync simply has none, so
 * the preparation steps fall away and the rsync covers the whole tree.
 */
final class DeploymentRunner
{
    private StepRecorder $recorder;

    private DeploymentOptions $options;

    /** True once anything on staging has actually changed. */
    private bool $stagingMutated = false;

    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
        private readonly DecisionGate $decisions,
        private readonly RollbackDeployment $rollback,
    ) {}

    public function run(Deployment $deployment): void
    {
        $this->recorder = new StepRecorder($deployment);
        $this->options = $deployment->opts();
        $this->stagingMutated = false;

        $deployment->loadMissing([
            'repoRefs.repositoryVersion.repository',
            'repoRefs.repositoryVersion.mediawikiVersion',
            'deploymentPatches.patch',
            'mediawikiVersion',
        ]);

        $this->markRunning($deployment);

        try {
            $this->execute($deployment);
        } catch (Throwable $exception) {
            Log::error('mwdeploy: deployment crashed', [
                'deployment' => $deployment->getKey(),
                'exception' => $exception,
            ]);

            $this->finish($deployment, DeploymentStatus::Failed, 'Deployment crashed: '.$exception->getMessage());

            throw $exception;
        }
    }

    private function execute(Deployment $deployment): void
    {
        $refs = $deployment->repoRefs;

        // Removing a whole core version requires that the deployment actually
        // recorded one, checked before anything is touched.
        if (! $this->guardVersionUndeploy($deployment)) {
            return;
        }

        if ($this->abortedByOperator($deployment)) {
            return;
        }

        // Record the undo point for every line item, before anything mutates.
        foreach ($refs as $ref) {
            $this->snapshot($deployment, $ref);
        }

        // A brand new core version needs its directory tree before anything can
        // be cloned into it.
        if (! $this->scaffoldVersion($deployment)) {
            return;
        }

        $removals = $this->removalPlanFor($deployment);
        $syncPlan = $this->calls->syncPlanFor($refs, $deployment->intent);

        // Removals on staging, then the checkouts that are being deployed.
        if (! $this->applyStagingRemovals($deployment, $removals)) {
            return;
        }

        if (! $this->applyCheckouts($deployment, $refs)) {
            return;
        }

        if (! $this->applyPatches($deployment)) {
            return;
        }

        if ($syncPlan->required && ! $this->runStagingStep($deployment, $this->calls->rsyncLocal($syncPlan))) {
            return;
        }

        if ($this->options->l10n
            && ! $this->runStagingStep($deployment, $this->calls->l10nRebuild($this->calls->stagingTarget()))) {
            return;
        }

        // The staging canary gates the fleet rollout.
        if (! $this->stagingCanary($deployment)) {
            return;
        }

        if ($this->options->stagingOnly) {
            $this->commitPresence($deployment);
            $this->finish($deployment, DeploymentStatus::Done, null);

            return;
        }

        $this->rollout($deployment, $syncPlan, $removals);
    }

    /**
     * A core version can only be undeployed if the deployment actually recorded
     * one. Whether any wiki still runs on it is left to whoever requests the
     * undeploy — the portal has no wiki → version map to check it against.
     */
    private function guardVersionUndeploy(Deployment $deployment): bool
    {
        if ($deployment->intent !== DeploymentIntent::VersionUndeploy) {
            return true;
        }

        if ($deployment->mediawikiVersion === null) {
            $this->finish($deployment, DeploymentStatus::Failed, 'No core version was recorded on this deployment.');

            return false;
        }

        return true;
    }

    private function scaffoldVersion(Deployment $deployment): bool
    {
        if ($deployment->intent !== DeploymentIntent::VersionCreate || $deployment->mediawikiVersion === null) {
            return true;
        }

        return $this->runStagingStep($deployment, $this->calls->versionScaffold($deployment->mediawikiVersion));
    }

    /**
     * Read the checkout's current state on staging and store it as this
     * deployment's undo point.
     *
     * Presence is recorded alongside the ref, which is what makes rollback
     * symmetric: undoing an undeploy, undoing a newly added extension and undoing
     * a plain ref change all fall out of the same three columns.
     */
    private function snapshot(Deployment $deployment, DeploymentRepoRef $ref): void
    {
        $checkout = $ref->repositoryVersion;

        if ($checkout === null) {
            return;
        }

        $wasPresent = $checkout->isPresent();
        $previousType = null;
        $previousValue = null;

        if ($wasPresent) {
            $call = $this->calls->gitHead($checkout);
            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            if ($result->ok) {
                $value = $result->payloadValue('ref');
                $type = $result->payloadValue('ref_type');

                $previousValue = is_string($value) && $value !== '' ? $value : null;
                $previousType = is_string($type)
                    ? (RefType::tryFrom($type)?->value ?? RefType::Commit->value)
                    : ($previousValue === null ? null : RefType::detect($previousValue)->value);
            } else {
                // The registry says it is on disk but staging disagrees. Record
                // that honestly rather than inventing a ref: rollback will skip
                // this checkout and say so.
                $this->recorder->note(
                    $step,
                    'no undo point recorded for '.$checkout->displayName().'; rollback will skip it',
                );
            }
        }

        $isUndeploy = $ref->action === RepoAction::Undeploy;

        $deployment->snapshots()->updateOrCreate(
            ['repository_version_id' => $checkout->getKey()],
            [
                'previous_present' => $wasPresent,
                'previous_ref_type' => $previousType,
                'previous_ref_value' => $previousValue,
                'new_present' => ! $isUndeploy,
                'new_ref_type' => $isUndeploy ? null : $ref->ref_type?->value,
                'new_ref_value' => $isUndeploy ? null : $ref->ref_value,
            ],
        );
    }

    private function removalPlanFor(Deployment $deployment): RemovalPlan
    {
        // A whole-version removal is one rm per host, not one per checkout inside
        // it. The per-checkout snapshots are still recorded above.
        if ($deployment->intent === DeploymentIntent::VersionUndeploy && $deployment->mediawikiVersion !== null) {
            return new RemovalPlan($this->calls, [], $deployment->mediawikiVersion);
        }

        $checkouts = $deployment->repoRefs
            ->filter(fn (DeploymentRepoRef $ref) => $ref->action === RepoAction::Undeploy)
            ->map(fn (DeploymentRepoRef $ref) => $ref->repositoryVersion)
            ->filter()
            ->values()
            ->all();

        return new RemovalPlan($this->calls, $checkouts);
    }

    private function applyStagingRemovals(Deployment $deployment, RemovalPlan $removals): bool
    {
        if ($removals->isEmpty()) {
            return true;
        }

        foreach ($removals->stagingCalls() as $call) {
            if ($this->abortedByOperator($deployment)) {
                return false;
            }

            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            if ($result->ok) {
                $this->stagingMutated = true;

                continue;
            }

            if ($this->options->force) {
                $this->recorder->note($step, 'continuing despite failure because --force is set');

                continue;
            }

            $this->abort($deployment, 'removal failed on staging: '.$result->detail());

            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, DeploymentRepoRef>  $refs
     */
    private function applyCheckouts(Deployment $deployment, Collection $refs): bool
    {
        foreach ($refs as $ref) {
            if ($this->abortedByOperator($deployment)) {
                return false;
            }

            if ($ref->action === RepoAction::Undeploy) {
                continue;
            }

            $checkout = $ref->repositoryVersion;

            if ($checkout === null || $ref->ref_value === null) {
                continue;
            }

            // Not on disk: clone it first. This is the path taken by a new
            // version's extensions, a newly registered repository, and undoing an
            // undeploy — all of which are the same operation.
            if (! $checkout->isPresent() && ! $this->cloneCheckout($deployment, $checkout)) {
                return false;
            }

            $call = $this->calls->gitCheckout($checkout, $ref->ref_value);
            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            if ($result->ok) {
                $this->stagingMutated = true;

                continue;
            }

            if ($this->options->force) {
                $this->recorder->note($step, 'continuing despite failure because --force is set');

                continue;
            }

            $this->abort(
                $deployment,
                sprintf('git checkout failed for %s: %s', $checkout->displayName(), $result->detail()),
            );

            return false;
        }

        return true;
    }

    private function cloneCheckout(Deployment $deployment, RepositoryVersion $checkout): bool
    {
        $call = $this->calls->repoRegister($checkout);
        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        if ($result->ok) {
            $this->stagingMutated = true;

            return true;
        }

        if ($this->options->force) {
            $this->recorder->note($step, 'continuing despite failure because --force is set');

            return true;
        }

        $this->abort(
            $deployment,
            sprintf('could not clone %s onto staging: %s', $checkout->displayName(), $result->detail()),
        );

        return false;
    }

    private function applyPatches(Deployment $deployment): bool
    {
        foreach ($deployment->deploymentPatches as $deploymentPatch) {
            if ($this->abortedByOperator($deployment)) {
                return false;
            }

            /** @var Patch|null $patch */
            $patch = $deploymentPatch->patch;

            if ($patch === null) {
                continue;
            }

            $call = $this->calls->patchApply($patch);
            $step = $this->recorder->begin($call);
            $result = $this->salt->run($call);
            $this->recorder->finish($step, $result);

            $deploymentPatch->update([
                'applied' => $result->ok,
                'applied_to_ref' => $this->refAppliedAgainst($deployment, $patch),
            ]);

            if ($result->ok || $this->options->force) {
                continue;
            }

            // A patch written against an older commit failing to apply is a normal
            // step failure, not a special case.
            $this->abort($deployment, sprintf('patch "%s" failed to apply: %s', $patch->name, $result->detail()));

            return false;
        }

        return true;
    }

    private function refAppliedAgainst(Deployment $deployment, Patch $patch): ?string
    {
        if ($patch->target_repository_version_id === null) {
            return null;
        }

        return $deployment->repoRefs
            ->firstWhere('repository_version_id', $patch->target_repository_version_id)
            ?->ref_value;
    }

    private function runStagingStep(Deployment $deployment, SaltCall $call): bool
    {
        if ($this->abortedByOperator($deployment)) {
            return false;
        }

        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        if ($result->ok) {
            return true;
        }

        if ($this->options->force) {
            $this->recorder->note($step, 'continuing despite failure because --force is set');

            return true;
        }

        $this->abort($deployment, $call->step()->label().' failed on staging: '.$result->detail());

        return false;
    }

    /**
     * On failure without --force this blocks on an operator decision, exactly as
     * the curses Prompter did, and defaults to abort-and-roll-back if nobody
     * answers.
     */
    private function stagingCanary(Deployment $deployment): bool
    {
        if ($this->abortedByOperator($deployment)) {
            return false;
        }

        $call = $this->calls->canary($this->calls->stagingTarget(), host: $this->calls->stagingCanaryHost());
        $step = $this->recorder->begin($call);
        $result = $this->salt->run($call);
        $this->recorder->finish($step, $result);

        if ($result->ok) {
            return true;
        }

        if ($this->options->force) {
            $this->recorder->note($step, 'canary failed but --force is set; continuing to rollout');

            return true;
        }

        $decision = $this->decisions->await($deployment, DecisionReason::StagingCanaryFailed, [
            'host' => $this->calls->stagingTarget(),
            'vhost' => (string) config('mwdeploy.rollout.canary_vhost'),
            'detail' => $result->detail(),
        ]);

        $this->decisions->clear($deployment);
        $this->recorder->note($step, 'operator decision: '.$decision->value);

        if ($decision === DeploymentDecision::Continue) {
            return true;
        }

        $this->abort(
            $deployment,
            'staging canary failed: '.$result->detail(),
            rollback: $decision === DeploymentDecision::AbortAndRollback,
        );

        return false;
    }

    private function rollout(Deployment $deployment, SyncPlan $syncPlan, RemovalPlan $removals): void
    {
        $servers = $this->appservers();

        if ($servers->isEmpty()) {
            $this->finish($deployment, DeploymentStatus::Failed, 'No active appservers matched this deployment.');

            return;
        }

        $pool = new RolloutPool(
            salt: $this->salt,
            calls: $this->calls,
            recorder: $this->recorder,
            decisions: $this->decisions,
            deployment: $deployment,
            servers: $servers,
            proxies: $this->proxies(),
            options: $this->options,
            syncPlan: $syncPlan,
            removals: $removals,
        );

        $results = $pool->run();

        $failed = array_keys(array_filter($results, fn (bool $ok) => ! $ok));

        if ($pool->abortRequested()) {
            $reason = $pool->abortedManually()
                ? 'aborted by operator during rollout, having already reached: '.implode(', ', array_keys($results))
                : 'aborted during rollout after a canary failure on: '.implode(', ', $failed);

            $this->abort(
                $deployment,
                $reason,
                rollback: $pool->rollbackRequested(),
                status: DeploymentStatus::Aborted,
            );

            return;
        }

        if ($failed !== []) {
            $this->finish($deployment, DeploymentStatus::Failed, 'Rollout failed on: '.implode(', ', $failed));

            return;
        }

        $this->commitPresence($deployment);
        $this->finish($deployment, DeploymentStatus::Done, null);
    }

    /**
     * Reconcile the registry with what is now on disk.
     *
     * Deliberately only on success: a deployment that failed halfway has left the
     * fleet in a state the registry cannot describe, and claiming otherwise would
     * make the next rollback wrong.
     */
    private function commitPresence(Deployment $deployment): void
    {
        foreach ($deployment->repoRefs as $ref) {
            $checkout = $ref->repositoryVersion;

            if ($checkout === null) {
                continue;
            }

            $ref->action === RepoAction::Undeploy
                ? $checkout->markUndeployed()
                : $checkout->markPresent();
        }

        $version = $deployment->mediawikiVersion;

        if ($version === null) {
            return;
        }

        if ($deployment->intent === DeploymentIntent::VersionUndeploy) {
            // Every checkout inside the version went with the directory, whether
            // or not it had its own line item.
            $version->checkouts()->update([
                'status' => PresenceStatus::Undeployed->value,
                'undeployed_at' => now(),
            ]);

            $version->forceFill([
                'status' => PresenceStatus::Undeployed->value,
                'undeployed_at' => now(),
            ])->save();

            return;
        }

        if ($deployment->intent === DeploymentIntent::VersionCreate) {
            $version->forceFill([
                'status' => PresenceStatus::Present->value,
                'undeployed_at' => null,
            ])->save();
        }
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function appservers(): Collection
    {
        $query = DeployTarget::query()
            ->active()
            ->role(TargetRole::Appserver)
            ->orderBy('sort_order')
            ->orderBy('hostname');

        // An empty server list means "all", matching `--servers all`.
        if ($this->options->servers !== []) {
            $query->whereIn('hostname', $this->options->servers);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function proxies(): Collection
    {
        return DeployTarget::query()
            ->active()
            ->role(TargetRole::Proxy)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();
    }

    private function markRunning(Deployment $deployment): void
    {
        $deployment->forceFill([
            'status' => DeploymentStatus::Running->value,
            'started_at' => $deployment->started_at ?? now(),
            'failure_reason' => null,
        ])->save();

        DeploymentProgressed::dispatch($deployment);
    }

    private function abort(
        Deployment $deployment,
        string $reason,
        bool $rollback = true,
        DeploymentStatus $status = DeploymentStatus::Failed,
    ): void {
        $this->finish($deployment, $status, $reason, autoRollback: $rollback);
    }

    /**
     * Checked at every checkpoint between Salt calls: a manual "abort" click
     * cannot interrupt a call already in flight, but it takes effect at the next
     * opportunity, the same way a canary-triggered abort already only takes hold
     * at a generator yield boundary rather than mid-call.
     */
    private function abortedByOperator(Deployment $deployment): bool
    {
        $rollback = $this->decisions->abortRequested($deployment);

        if ($rollback === null) {
            return false;
        }

        $this->abort($deployment, 'aborted by operator', rollback: $rollback, status: DeploymentStatus::Aborted);

        return true;
    }

    private function finish(
        Deployment $deployment,
        DeploymentStatus $status,
        ?string $reason,
        bool $autoRollback = false,
    ): void {
        $deployment->forceFill([
            'status' => $status->value,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ])->save();

        $this->markPendingStepsSkipped($deployment, $status);

        // A successful rollback retires the steps it undid, so history shows
        // which work was reverted rather than leaving it green.
        if ($status === DeploymentStatus::Done && $deployment->isRollback()) {
            $this->retireRolledBackSteps($deployment);
        }

        DeploymentProgressed::dispatch($deployment);

        if (! $autoRollback || $status === DeploymentStatus::Done) {
            return;
        }

        $this->maybeAutoRollback($deployment, $reason);
    }

    private function maybeAutoRollback(Deployment $deployment, ?string $reason): void
    {
        // Cap automatic rollback at one hop: auto-rolling-back a rollback is how
        // one bad deploy becomes an outage.
        if ($deployment->isRollback()) {
            $deployment->forceFill([
                'failure_reason' => trim(($reason ?? '').' — this deployment is itself a rollback, so no automatic '
                    .'rollback was enqueued. Manual intervention required.'),
            ])->save();

            DeploymentProgressed::dispatch($deployment);

            return;
        }

        if ($this->options->force || ! $this->stagingMutated) {
            return;
        }

        $rollback = ($this->rollback)(
            failed: $deployment,
            actor: null,
            servers: $deployment->touchedAppservers(),
        );

        if ($rollback === null) {
            $deployment->forceFill([
                'failure_reason' => trim(($reason ?? '').' — no usable undo point was recorded, so no automatic '
                    .'rollback could be enqueued.'),
            ])->save();

            DeploymentProgressed::dispatch($deployment);
        }
    }

    private function markPendingStepsSkipped(Deployment $deployment, DeploymentStatus $status): void
    {
        if ($status === DeploymentStatus::Done) {
            return;
        }

        $deployment->steps()
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->get()
            ->each(function (DeploymentStep $step) {
                $step->status = StepStatus::Skipped;
                $step->finished_at = now();
                $step->log = trim(($step->log ?? '')."\nskipped: deployment ended");
                $step->save();
            });
    }

    private function retireRolledBackSteps(Deployment $rollbackDeployment): void
    {
        $original = $rollbackDeployment->rollsBack;

        if ($original === null) {
            return;
        }

        $hosts = $rollbackDeployment->steps()->distinct()->pluck('target_hostname');

        $original->steps()
            ->whereIn('target_hostname', $hosts)
            ->where('status', StepStatus::Done->value)
            ->update(['status' => StepStatus::RolledBack->value]);
    }
}
