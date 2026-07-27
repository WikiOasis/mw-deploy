<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\RepositoryType;
use App\Models\Deployment;
use App\Models\Repository;
use App\Models\User;
use App\Services\Discovery\ImportPlanner;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\ScannedCheckout;
use App\Services\Discovery\TreeScanner;
use App\Support\DeploymentOptions;
use App\Support\PathResolver;
use Illuminate\Support\Facades\Log;

/**
 * Register the config repository from a git URL and nothing else.
 *
 * mw-config is the one repository every farm has exactly one of, it always lives
 * at the same place in the tree, and it is never versioned — so asking for a name,
 * a type, a path and a set of versions is four questions with only one possible
 * set of answers. This takes the URL.
 *
 * It also handles the two situations a farm can be in, without making the operator
 * work out which one they are in:
 *
 *   config/ is already checked out  → adopt it, registry only. No clone: the
 *                                     directory is already there and cloning over
 *                                     it would fail or, worse, half-succeed.
 *   config/ is not there yet        → register and clone it onto staging, as an
 *                                     ordinary reviewable deployment.
 */
final class RegisterConfigRepository
{
    public function __construct(
        private readonly RegisterRepository $register,
        private readonly RegisterCheckout $registerCheckout,
        private readonly TreeScanner $scanner,
        private readonly ImportPlanner $planner,
        private readonly PathResolver $paths,
    ) {}

    /**
     * @return array{repository: Repository|null, deployment: Deployment|null, adopted: bool, error: string|null}
     */
    public function __invoke(
        User $actor,
        string $gitUrl,
        ?string $branch = null,
        ?string $name = null,
        bool $dispatch = true,
    ): array {
        $name = $this->resolveName($name, $gitUrl);
        $onDisk = $this->configOnDisk();

        if ($onDisk !== null) {
            $repository = Repository::query()->updateOrCreate(
                ['type' => RepositoryType::Config->value, 'name' => $name],
                [
                    'git_url' => $onDisk->gitUrl ?? $gitUrl,
                    'default_branch' => $branch ?? $onDisk->inferredDefaultBranch(),
                    'active' => true,
                    'created_by' => $actor->getKey(),
                    'discovered_at' => now(),
                ],
            );

            $checkout = $this->registerCheckout->ensure($repository, null);

            $checkout->forceFill([
                'path' => $onDisk->path,
                'tracked_ref_value' => $onDisk->ref ?? $repository->default_branch,
                'tracked_ref_type' => $onDisk->refType?->value,
                'observed_ref_type' => $onDisk->refType?->value,
                'observed_ref_value' => $onDisk->ref,
                'observed_commit' => $onDisk->commit,
                'observed_at' => now(),
                'discovered_at' => $checkout->discovered_at ?? now(),
            ])->save();

            $checkout->markPresent();

            return [
                'repository' => $repository,
                'deployment' => null,
                'adopted' => true,
                'error' => null,
            ];
        }

        $outcome = ($this->register)(
            attributes: [
                'name' => $name,
                'type' => RepositoryType::Config->value,
                'git_url' => $gitUrl,
                'default_branch' => $branch ?? 'master',
                'in_use' => true,
            ],
            actor: $actor,
            // Config is unversioned by definition, so there are no version ids to
            // pass: RegisterRepository reads the empty list as "one top-level
            // checkout", which for a config repository is the only shape there is.
            versionIds: [],
            refs: [],
            options: new DeploymentOptions(stagingOnly: true),
            dispatch: $dispatch,
        );

        return [
            'repository' => $outcome['repository'],
            'deployment' => $outcome['deployment'],
            'adopted' => false,
            'error' => $outcome['error'],
        ];
    }

    /**
     * The config checkout as it exists on disk, if it does.
     *
     * A scan failure is not fatal here: it means the portal cannot see the tree, in
     * which case falling through to "register and clone" is the correct guess — the
     * shim refuses to clone into a non-empty directory, so the wrong guess fails
     * safely rather than overwriting a config repository.
     */
    private function configOnDisk(): ?ScannedCheckout
    {
        try {
            $config = $this->scanner->scan()->config();
        } catch (ScanFailed $failure) {
            Log::info('Config repository registration could not scan the tree first.', [
                'error' => $failure->getMessage(),
            ]);

            return null;
        }

        return $config?->isImportable() === true ? $config : null;
    }

    /**
     * Name it after the remote — "mw-config" for …/wikioasis/mw-config.git — since
     * that is what an operator would have typed anyway.
     */
    private function resolveName(?string $name, string $gitUrl): string
    {
        $name = trim((string) $name);

        if ($name !== '') {
            return $name;
        }

        $basename = preg_replace('/\.git$/i', '', basename(rtrim($gitUrl, '/')));
        $basename = $this->paths->sanitiseSegment((string) $basename);

        return $basename === ''
            ? (string) config('mwdeploy.discovery.config_repository_name', 'mw-config')
            : $basename;
    }
}
