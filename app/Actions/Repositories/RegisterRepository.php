<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Actions\Deployments\CreateDeployment;
use App\Enums\DeploymentIntent;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Enums\RepositoryType;
use App\Models\Deployment;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\User;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use App\Support\DeploymentOptions;
use Illuminate\Support\Facades\DB;

/**
 * Register a repository and, optionally, deploy it into one or more core versions.
 *
 * The registry row and its checkouts are metadata; the cloning is a *deployment*,
 * which is what makes adding an extension reviewable on the plan screen, visible
 * on the live dashboard, and undoable — rolling that deployment back removes the
 * checkouts again, because their snapshots say they were absent beforehand.
 *
 * The remote is checked for reachability before anything is written, so a typo in
 * the URL fails at the form rather than as a puzzling deployment failure later.
 */
final class RegisterRepository
{
    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
        private readonly RegisterCheckout $registerCheckout,
        private readonly CreateDeployment $createDeployment,
    ) {}

    /**
     * @param  array{name: string, type: string, git_url: string, default_branch: string, in_use?: bool}  $attributes
     * @param  list<int>  $versionIds  core versions to add a checkout in; empty
     *                                 means unversioned (top level)
     * @param  array<int, array{ref_mode?: string, ref?: string|null}>  $refs  keyed
     *                                                                         by version id
     * @return array{repository: Repository|null, deployment: Deployment|null, error: string|null}
     */
    public function __invoke(
        array $attributes,
        User $actor,
        array $versionIds = [],
        array $refs = [],
        ?DeploymentOptions $options = null,
        bool $dispatch = true,
    ): array {
        $type = RepositoryType::from($attributes['type']);

        // Check the remote before writing anything: an unreachable URL must not
        // leave a registry entry behind that breaks every wizard referencing it.
        $check = $this->salt->run($this->calls->gitRemoteCheck(
            $attributes['git_url'],
            $attributes['default_branch'],
        ));

        if (! $check->ok) {
            return [
                'repository' => null,
                'deployment' => null,
                'error' => 'Could not reach '.$attributes['git_url'].': '.$check->detail(),
            ];
        }

        $versions = $type->isVersioned() && $versionIds !== []
            ? MediaWikiVersion::query()->whereIn('id', $versionIds)->get()
            : collect([null]);

        [$repository, $lineItems] = DB::transaction(function () use ($attributes, $actor, $type, $versions, $refs): array {
            $repository = Repository::query()->updateOrCreate(
                ['type' => $type->value, 'name' => $attributes['name']],
                [
                    'git_url' => $attributes['git_url'],
                    'default_branch' => $attributes['default_branch'],
                    'in_use' => (bool) ($attributes['in_use'] ?? false),
                    'active' => true,
                    'created_by' => $actor->getKey(),
                ],
            );

            $lineItems = [];

            foreach ($versions as $version) {
                $override = $refs[$version?->getKey() ?? 0] ?? [];

                $refMode = RefMode::tryFrom((string) ($override['ref_mode'] ?? '')) ?? RefMode::Pinned;
                $ref = $override['ref'] ?? null;
                $ref = is_string($ref) && trim($ref) !== '' ? trim($ref) : $repository->default_branch;

                $checkout = ($this->registerCheckout)($repository, $version, $refMode, $ref);

                $lineItems[] = [
                    'repository_version_id' => $checkout->getKey(),
                    'action' => RepoAction::Deploy->value,
                    'ref_type' => RefType::detect($ref)->value,
                    'ref_value' => $ref,
                ];
            }

            return [$repository, $lineItems];
        });

        $deployment = $lineItems === [] ? null : ($this->createDeployment)(
            actor: $actor,
            refs: $lineItems,
            patchIds: [],
            options: $options ?? new DeploymentOptions(stagingOnly: true),
            intent: DeploymentIntent::Deploy,
            dispatch: $dispatch,
        );

        return ['repository' => $repository, 'deployment' => $deployment, 'error' => null];
    }
}
