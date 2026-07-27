<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\RepositoryType;
use App\Models\Repository;
use App\Models\User;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use App\Support\PathResolver;

/**
 * Section 4.5: registering a repository clones it into the staging tree in the
 * same step, so it is immediately usable by the ref picker rather than needing a
 * separate manual clone on the box.
 *
 * The clone runs *first*: a failed clone must not leave a broken registry entry
 * behind that then fails every deployment wizard referencing it.
 */
final class RegisterRepository
{
    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
        private readonly PathResolver $paths,
    ) {}

    /**
     * @param  array{name: string, type: string, git_url: string, default_branch: string, core_version?: string|null, in_use?: bool}  $attributes
     * @return array{repository: Repository|null, error: string|null, detail: string|null}
     */
    public function __invoke(array $attributes, User $actor): array
    {
        $type = RepositoryType::from($attributes['type']);
        $coreVersion = $attributes['core_version'] ?? null;
        $coreVersion = is_string($coreVersion) && $coreVersion !== '' ? $coreVersion : null;

        $path = $this->paths->relativePath($type, $attributes['name'], $coreVersion);

        if ($path === '' || str_contains($path, '//')) {
            return [
                'repository' => null,
                'error' => 'Could not derive a safe staging path from that name.',
                'detail' => null,
            ];
        }

        // Unsaved: this exists only so ShimCalls can build the register command
        // against the path we are about to commit to.
        $repository = new Repository([
            'name' => $attributes['name'],
            'type' => $type->value,
            'git_url' => $attributes['git_url'],
            'default_branch' => $attributes['default_branch'],
            'core_version' => $coreVersion,
            'path' => $path,
            'in_use' => (bool) ($attributes['in_use'] ?? false),
            'active' => true,
            'created_by' => $actor->getKey(),
        ]);

        $result = $this->salt->run($this->calls->repoRegister($repository));

        if (! $result->ok) {
            return [
                'repository' => null,
                'error' => 'Could not clone '.$attributes['git_url'].' onto the staging host.',
                'detail' => $result->detail(),
            ];
        }

        $repository->registered_at = now();
        $repository->save();

        return ['repository' => $repository, 'error' => null, 'detail' => $result->detail()];
    }
}
