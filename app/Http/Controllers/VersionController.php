<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Versions\CreateVersion;
use App\Actions\Versions\UndeployVersion;
use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Support\DeploymentOptions;
use App\Support\PathResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The MediaWiki core version lifecycle: list, cut a new one from an existing one,
 * and remove one.
 */
final class VersionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', MediaWikiVersion::class);

        $versions = MediaWikiVersion::query()
            ->with(['createdFrom', 'creator', 'checkouts.repository'])
            ->orderByDesc('version')
            ->get();

        return view('versions.index', [
            'versions' => $versions,
            'coreRepository' => Repository::query()->active()->ofType(RepositoryType::Core)->first(),
            'maxParallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
            'wikiVersionCheckEnabled' => (bool) config('mwdeploy.versions.require_wiki_version_check', true),
            'wikiVersionsPath' => (string) config('mwdeploy.paths.wiki_versions'),
        ]);
    }

    public function show(MediaWikiVersion $version): View
    {
        $this->authorize('view', $version);

        $version->load(['createdFrom', 'creator', 'checkouts.repository', 'deployments.creator']);

        return view('versions.show', [
            'version' => $version,
            'checkoutsByType' => $version->checkouts
                ->sortBy(fn ($checkout) => $checkout->repository?->name ?? '')
                ->groupBy(fn ($checkout) => $checkout->repository?->type->value ?? 'unknown'),
            'types' => RepositoryType::cases(),
        ]);
    }

    /**
     * Cut a new version by reconstructing it from an existing one: scaffold the
     * tree, then clone core and every extension and skin the source version has.
     */
    public function store(Request $request, CreateVersion $createVersion, PathResolver $paths): RedirectResponse
    {
        $this->authorize('create', MediaWikiVersion::class);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9]+\.[0-9]+$/', Rule::unique('mediawiki_versions', 'version')],
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
            return back()->withInput()->withErrors(['version' => $outcome['error']]);
        }

        return redirect()
            ->route('deployments.show', $outcome['deployment'])
            ->with('status', sprintf(
                'Building %s from %s — %d checkout(s) queued.',
                $outcome['version']->version,
                $source?->version ?? 'nothing (core only)',
                $outcome['deployment']->repoRefs()->count(),
            ));
    }

    /**
     * Remove a whole core version. The runner refuses if the farm's wiki → version
     * map still shows wikis on it, and fails closed if that map cannot be read.
     */
    public function undeploy(Request $request, MediaWikiVersion $version, UndeployVersion $undeploy): RedirectResponse
    {
        $this->authorize('undeploy', $version);

        $validated = $request->validate([
            // Typing the version out is cheap insurance against removing the
            // wrong one from a list where every row looks the same.
            'confirm_version' => ['required', 'string'],
            'parallel' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('mwdeploy.rollout.max_parallel', 8)],
            'rollout' => ['sometimes', 'boolean'],
        ]);

        if ($validated['confirm_version'] !== $version->version) {
            return back()->withErrors([
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
            return back()->withErrors(['confirm_version' => $outcome['error']]);
        }

        return redirect()
            ->route('deployments.show', $outcome['deployment'])
            ->with('status', 'Removal of '.$version->version.' queued. It will be refused if any wiki still uses it.');
    }
}
