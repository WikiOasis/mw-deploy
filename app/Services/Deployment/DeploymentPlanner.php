<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\DeploymentIntent;
use App\Enums\RepoAction;
use App\Enums\StepName;
use App\Enums\TargetRole;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Collection;

/**
 * Renders the exact sequence of Salt calls a deployment will make, in order,
 * before anything runs.
 *
 * It builds those calls through the same ShimCalls factory the runner uses, so the
 * review screen cannot drift from what actually executes. That matters most for
 * removals: an operator about to delete a checkout off the whole fleet should see
 * the literal `repo-remove` argv, root guard included.
 */
final class DeploymentPlanner
{
    public function __construct(private readonly ShimCalls $calls) {}

    /**
     * @param  Collection<int, DeploymentRepoRef>  $refs  may be unsaved models
     * @param  Collection<int, Patch>  $patches
     * @return list<PlannedCall>
     */
    public function plan(
        Collection $refs,
        Collection $patches,
        DeploymentOptions $options,
        DeploymentIntent $intent = DeploymentIntent::Deploy,
        ?MediaWikiVersion $version = null,
    ): array {
        $planned = [];

        if ($intent === DeploymentIntent::VersionUndeploy && $version !== null) {
            $planned[] = new PlannedCall('Preparation', $this->calls->wikiVersions());
        }

        // The undo point, read before anything mutates.
        foreach ($refs as $ref) {
            $checkout = $ref->repositoryVersion;

            if ($checkout !== null && $checkout->isPresent()) {
                $planned[] = new PlannedCall('Preparation', $this->calls->gitHead($checkout));
            }
        }

        if ($intent === DeploymentIntent::VersionCreate && $version !== null) {
            $planned[] = new PlannedCall('Preparation', $this->calls->versionScaffold($version));
        }

        $removals = $this->removalPlan($refs, $intent, $version);

        foreach ($removals->stagingCalls() as $call) {
            $planned[] = new PlannedCall('Preparation', $call);
        }

        foreach ($refs as $ref) {
            if ($ref->action === RepoAction::Undeploy) {
                continue;
            }

            $checkout = $ref->repositoryVersion;

            if ($checkout === null || $ref->ref_value === null) {
                continue;
            }

            // Not on disk yet: it has to be cloned before it can be checked out.
            if (! $checkout->isPresent()) {
                $planned[] = new PlannedCall('Preparation', $this->calls->repoRegister($checkout));
            }

            $planned[] = new PlannedCall('Preparation', $this->calls->gitCheckout($checkout, $ref->ref_value));
        }

        foreach ($patches as $patch) {
            $planned[] = new PlannedCall('Preparation', $this->calls->patchApply($patch));
        }

        $syncPlan = $this->calls->syncPlanFor($refs);

        if ($syncPlan->required) {
            $planned[] = new PlannedCall('Preparation', $this->calls->rsyncLocal($syncPlan));
        }

        if ($options->l10n) {
            $planned[] = new PlannedCall('Preparation', $this->calls->l10nRebuild($this->calls->stagingTarget()));
        }

        $planned[] = new PlannedCall('Preparation', $this->calls->canary($this->calls->stagingTarget()));

        if ($options->stagingOnly) {
            return $planned;
        }

        $proxies = $options->rollout ? $this->proxies() : collect();

        foreach ($this->servers($options) as $server) {
            $phase = 'Rollout — '.$server->hostname;

            if ($options->rollout) {
                foreach ($proxies as $proxy) {
                    $planned[] = new PlannedCall(
                        $phase,
                        $this->calls->haproxy(StepName::HaproxyDepool, $proxy, $server),
                    );
                }
            }

            foreach ($removals->callsFor($server->hostname) as $call) {
                $planned[] = new PlannedCall($phase, $call);
            }

            if ($syncPlan->required) {
                $planned[] = new PlannedCall($phase, $this->calls->rsyncRemote($server, $syncPlan));
            }

            if ($options->l10n) {
                $planned[] = new PlannedCall($phase, $this->calls->l10nRebuild($server->hostname));
            }

            $planned[] = new PlannedCall(
                $phase,
                $this->calls->canary($server->hostname, $server->canaryVhost()),
            );

            if ($options->rollout) {
                foreach ($proxies as $proxy) {
                    $planned[] = new PlannedCall(
                        $phase,
                        $this->calls->haproxy(StepName::HaproxyRepool, $proxy, $server),
                    );
                }
            }
        }

        return $planned;
    }

    /**
     * @param  Collection<int, DeploymentRepoRef>  $refs
     */
    private function removalPlan(
        Collection $refs,
        DeploymentIntent $intent,
        ?MediaWikiVersion $version,
    ): RemovalPlan {
        if ($intent === DeploymentIntent::VersionUndeploy && $version !== null) {
            return new RemovalPlan($this->calls, [], $version);
        }

        $checkouts = $refs
            ->filter(fn (DeploymentRepoRef $ref) => $ref->action === RepoAction::Undeploy)
            ->map(fn (DeploymentRepoRef $ref) => $ref->repositoryVersion)
            ->filter()
            ->values()
            ->all();

        return new RemovalPlan($this->calls, $checkouts);
    }

    /**
     * Convenience for the wizard: turn raw form input into the unsaved line-item
     * models the planner and ShimCalls expect.
     *
     * @param  array<int, array{checkout: RepositoryVersion, action: RepoAction, ref_type: ?string, ref_value: ?string}>  $selections
     * @return Collection<int, DeploymentRepoRef>
     */
    public function refsFromSelections(array $selections): Collection
    {
        return collect($selections)->map(function (array $selection): DeploymentRepoRef {
            $ref = new DeploymentRepoRef([
                'repository_version_id' => $selection['checkout']->getKey(),
                'action' => $selection['action']->value,
                'ref_type' => $selection['ref_type'],
                'ref_value' => $selection['ref_value'],
            ]);

            $ref->setRelation('repositoryVersion', $selection['checkout']);

            return $ref;
        });
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function servers(DeploymentOptions $options): Collection
    {
        $query = DeployTarget::query()
            ->active()
            ->role(TargetRole::Appserver)
            ->orderBy('sort_order')
            ->orderBy('hostname');

        if ($options->servers !== []) {
            $query->whereIn('hostname', $options->servers);
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
}
