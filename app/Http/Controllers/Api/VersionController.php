<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Versions\CreateVersion;
use App\Actions\Versions\UndeployVersion;
use App\Enums\RepositoryType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\VersionResource;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Support\DeploymentOptions;
use App\Support\PathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The MediaWiki core version lifecycle: list, cut a new one from an existing one,
 * and remove one.
 */
final class VersionController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MediaWikiVersion::class);

        $versions = MediaWikiVersion::query()
            ->with(['createdFrom', 'creator', 'checkouts.repository'])
            ->orderByDesc('version')
            ->get();

        $core = Repository::query()->active()->ofType(RepositoryType::Core)->first();

        return response()->json([
            'data' => VersionResource::collection($versions)->resolve(),
            'core_repository' => $core === null ? null : [
                'id' => $core->getKey(),
                'name' => $core->name,
                'git_url' => $core->git_url,
                'default_branch' => $core->default_branch,
            ],
            'settings' => [
                'max_parallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
            ],
            'can' => [
                'create' => request()->user()?->can('create', MediaWikiVersion::class) ?? false,
            ],
        ]);
    }

    public function show(MediaWikiVersion $version): JsonResponse
    {
        $this->authorize('view', $version);

        $version->load([
            'createdFrom', 'creator',
            'checkouts.repository', 'checkouts.mediawikiVersion',
            'deployments.creator', 'deployments.repoRefs.repositoryVersion.repository',
        ]);

        return response()->json([
            'data' => (new VersionResource($version))->resolve(),
            'deployments' => DeploymentResource::collection(
                $version->deployments->sortByDesc('id')->values()
            )->resolve(),
        ]);
    }

    /**
     * Cut a new version by reconstructing it from an existing one: scaffold the
     * tree, then clone core and every extension and skin the source version has.
     */
    public function store(Request $request, CreateVersion $createVersion, PathResolver $paths): JsonResponse
    {
        $this->authorize('create', MediaWikiVersion::class);

        $validated = $request->validate([
            'version' => [
                'required', 'string', 'max:20', 'regex:/^[0-9]+\.[0-9]+$/',
                Rule::unique('mediawiki_versions', 'version'),
            ],
            'source_id' => ['nullable', 'integer', Rule::exists('mediawiki_versions', 'id')],
            'core_ref' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'parallel' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('mwdeploy.rollout.max_parallel', 8)],
            'staging_only' => ['sometimes', 'boolean'],
            'rollout' => ['sometimes', 'boolean'],
            'l10n' => ['sometimes', 'boolean'],
        ], [
            'version.regex' => 'Give a MediaWiki version like 1.46.',
            'version.unique' => 'That version already exists.',
        ]);

        $source = $validated['source_id'] === null
            ? null
            : MediaWikiVersion::query()->find($validated['source_id']);

        $outcome = $createVersion(
            actor: $request->user(),
            version: $validated['version'],
            source: $source,
            coreRef: $validated['core_ref'],
            options: new DeploymentOptions(
                parallel: (int) ($validated['parallel'] ?? 1),
                l10n: $request->boolean('l10n'),
                rollout: $request->boolean('rollout'),
                // A brand new version serves no traffic yet, so staging-only is
                // the sane default: build it, check it, then roll it out.
                stagingOnly: $request->boolean('staging_only', true),
            ),
        );

        if ($outcome['error'] !== null) {
            throw ValidationException::withMessages(['version' => $outcome['error']]);
        }

        return response()->json([
            'version' => (new VersionResource($outcome['version']))->resolve(),
            'deployment_id' => $outcome['deployment']->getKey(),
            'message' => sprintf(
                'Building %s from %s — %d checkout(s) queued.',
                $outcome['version']->version,
                $source?->version ?? 'nothing (core only)',
                $outcome['deployment']->repoRefs()->count(),
            ),
        ], 201);
    }

    /**
     * Remove a whole core version. The runner refuses if the farm's wiki → version
     * map still shows wikis on it, and fails closed if that map cannot be read.
     */
    public function undeploy(Request $request, MediaWikiVersion $version, UndeployVersion $undeploy): JsonResponse
    {
        $this->authorize('undeploy', $version);

        $validated = $request->validate([
            // Typing the version out is cheap insurance against removing the wrong
            // one from a list where every row looks the same.
            'confirm_version' => ['required', 'string'],
            'parallel' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('mwdeploy.rollout.max_parallel', 8)],
            'rollout' => ['sometimes', 'boolean'],
        ]);

        if ($validated['confirm_version'] !== $version->version) {
            throw ValidationException::withMessages([
                'confirm_version' => 'Type '.$version->version.' exactly to confirm removing it.',
            ]);
        }

        $outcome = $undeploy(
            actor: $request->user(),
            version: $version,
            options: new DeploymentOptions(
                parallel: (int) ($validated['parallel'] ?? 1),
                rollout: $request->boolean('rollout'),
            ),
        );

        if ($outcome['error'] !== null) {
            throw ValidationException::withMessages(['confirm_version' => $outcome['error']]);
        }

        return response()->json([
            'deployment_id' => $outcome['deployment']->getKey(),
            'message' => 'Removal of '.$version->version.' queued. It will be refused if any wiki still uses it.',
        ], 201);
    }
}
