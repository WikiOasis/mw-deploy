<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\StepName;
use App\Enums\TargetRole;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Collection;

/**
 * Renders the exact sequence of Salt calls a deployment will make, in order,
 * before anything runs (wizard step 6).
 *
 * It builds those calls through the same ShimCalls factory the runner uses, so
 * the review screen cannot drift from what actually executes.
 */
final class DeploymentPlanner
{
    public function __construct(private readonly ShimCalls $calls) {}

    /**
     * @param  Collection<int, DeploymentRepoRef>  $refs  may be unsaved models
     * @param  Collection<int, Patch>  $patches
     * @return list<PlannedCall>
     */
    public function plan(Collection $refs, Collection $patches, DeploymentOptions $options): array
    {
        $planned = [];

        foreach ($refs as $ref) {
            $planned[] = new PlannedCall('Preparation', $this->calls->gitHead($ref->repository));
        }

        foreach ($refs as $ref) {
            $planned[] = new PlannedCall('Preparation', $this->calls->gitCheckout($ref));
        }

        foreach ($patches as $patch) {
            $planned[] = new PlannedCall('Preparation', $this->calls->patchApply($patch));
        }

        $syncPaths = $this->calls->requiresFullTreeSync($refs, $options)
            ? []
            : $this->calls->relativePathsFor($refs);

        $planned[] = new PlannedCall('Preparation', $this->calls->rsyncLocal($syncPaths));

        if ($options->l10n) {
            $planned[] = new PlannedCall('Preparation', $this->calls->l10nRebuild($this->calls->stagingTarget()));
        }

        $planned[] = new PlannedCall('Preparation', $this->calls->canary($this->calls->stagingTarget()));

        if ($options->stagingOnly) {
            return $planned;
        }

        $proxies = $this->proxies($options);

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

            $planned[] = new PlannedCall($phase, $this->calls->rsyncRemote($server, $syncPaths));

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
     * Convenience for the wizard: turn raw form input into the unsaved repo-ref
     * models the planner and ShimCalls expect.
     *
     * @param  array<int, array{repository: Repository, ref_type: string, ref_value: string}>  $selections
     * @return Collection<int, DeploymentRepoRef>
     */
    public function refsFromSelections(array $selections): Collection
    {
        return collect($selections)->map(function (array $selection): DeploymentRepoRef {
            $ref = new DeploymentRepoRef([
                'repository_id' => $selection['repository']->getKey(),
                'ref_type' => $selection['ref_type'],
                'ref_value' => $selection['ref_value'],
            ]);

            $ref->setRelation('repository', $selection['repository']);

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
    private function proxies(DeploymentOptions $options): Collection
    {
        if (! $options->rollout) {
            return collect();
        }

        return DeployTarget::query()
            ->active()
            ->role(TargetRole::Proxy)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();
    }
}
