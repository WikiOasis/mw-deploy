<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Repositories\RegisterConfigRepository;
use App\Enums\RepositoryType;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryResource;
use App\Models\Repository;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\TreeScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Registering the config repository, in one field.
 *
 * mw-config is the repository every farm has exactly one of, always at the same
 * place in the tree, never versioned — so the generic repository form's name, type,
 * version and pin questions all have exactly one right answer. This endpoint takes
 * the git URL and works the rest out, including whether the checkout is already on
 * disk (adopt it) or is not (clone it onto staging).
 */
final class ConfigRepositoryController extends Controller
{
    /**
     * What the portal knows about config right now: the registered repository if
     * there is one, and what the tree has at the configured config path.
     */
    public function show(TreeScanner $scanner): JsonResponse
    {
        $this->authorize('viewAny', Repository::class);

        $registered = Repository::query()
            ->with(['versions.mediawikiVersion'])
            ->ofType(RepositoryType::Config)
            ->where('active', true)
            ->first();

        $onDisk = null;
        $scanError = null;

        try {
            $config = $scanner->scan()->config();

            if ($config !== null) {
                $onDisk = [
                    'path' => $config->path,
                    'is_git' => $config->isGit,
                    'git_url' => $config->gitUrl,
                    'ref' => $config->ref,
                    'commit' => $config->commit,
                    'default_branch' => $config->defaultBranch,
                    'importable' => $config->isImportable(),
                    'blocker' => $config->blocker(),
                ];
            }
        } catch (ScanFailed $failure) {
            // Not an error for this screen: the form still works, it just cannot
            // pre-fill from the tree or tell the operator what is already there.
            $scanError = $failure->getMessage();
        }

        return response()->json([
            'repository' => $registered === null ? null : (new RepositoryResource($registered))->resolve(),
            'config_dir' => (string) config('mwdeploy.paths.config_dir'),
            'suggested_name' => (string) config('mwdeploy.discovery.config_repository_name'),
            'on_disk' => $onDisk,
            'scan_error' => $scanError,
            'can_manage' => $this->canManage(),
        ]);
    }

    public function store(Request $request, RegisterConfigRepository $register): JsonResponse
    {
        $this->authorize('create', Repository::class);

        $validated = $request->validate([
            'git_url' => ['required', 'string', 'max:500', 'regex:#^(https://[^\s]+|git@[^\s:]+:[^\s]+)$#'],
            'default_branch' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ], [
            'git_url.regex' => 'Give an https:// or git@host:path remote.',
        ]);

        $outcome = $register(
            actor: $request->user(),
            gitUrl: $validated['git_url'],
            branch: $validated['default_branch'] ?? null,
            name: $validated['name'] ?? null,
        );

        if ($outcome['error'] !== null) {
            throw ValidationException::withMessages(['git_url' => $outcome['error']]);
        }

        $repository = $outcome['repository'];
        $repository?->load(['versions.mediawikiVersion']);

        return response()->json([
            'repository' => $repository === null ? null : (new RepositoryResource($repository))->resolve(),
            'deployment_id' => $outcome['deployment']?->getKey(),
            'adopted' => $outcome['adopted'],
            'message' => $outcome['adopted']
                ? $repository->name.' is already checked out on disk, so it was adopted into the registry. '
                    .'Nothing was cloned.'
                : $repository->name.' registered. Cloning it onto staging.',
        ], 201);
    }

    private function canManage(): bool
    {
        return request()->user()?->can('create', Repository::class) === true;
    }
}
