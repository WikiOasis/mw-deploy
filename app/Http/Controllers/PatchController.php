<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePatchRequest;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Section 4.5's patch registry.
 *
 * The point of storing target_path and target_repo_id on the patch row is that
 * an operator never retypes the target directory at deploy time, which is the
 * class of mistake the `--patch`/`--patch-target` flag pair invites.
 */
final class PatchController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Patch::class);

        return view('patches.index', [
            'patches' => Patch::query()
                ->with(['targetCheckout.repository', 'targetCheckout.mediawikiVersion', 'creator'])
                ->orderBy('active', 'desc')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Patch::class);

        return view('patches.create', [
            'checkouts' => $this->checkoutOptions(),
        ]);
    }

    public function store(StorePatchRequest $request): RedirectResponse
    {
        $patch = Patch::create([
            ...$request->safe()->except('patch_file', 'active'),
            'active' => $request->boolean('active', true),
            'file_path' => $this->storeFile($request),
            'original_filename' => $request->file('patch_file')?->getClientOriginalName(),
            'created_by' => $request->user()->getKey(),
        ]);

        return redirect()
            ->route('patches.index')
            ->with('status', 'Patch "'.$patch->name.'" registered. It will be pre-selected on deployments touching its repository.');
    }

    public function edit(Patch $patch): View
    {
        $this->authorize('update', $patch);

        return view('patches.edit', [
            'patch' => $patch,
            'checkouts' => $this->checkoutOptions(),
        ]);
    }

    public function update(StorePatchRequest $request, Patch $patch): RedirectResponse
    {
        $attributes = [
            ...$request->safe()->except('patch_file', 'active'),
            'active' => $request->boolean('active'),
        ];

        if ($request->hasFile('patch_file')) {
            Storage::disk('patches')->delete($patch->file_path);

            $attributes['file_path'] = $this->storeFile($request);
            $attributes['original_filename'] = $request->file('patch_file')?->getClientOriginalName();
            // A replaced file invalidates the previous dry-run verdict.
            $attributes['last_checked_at'] = null;
            $attributes['last_check_ok'] = null;
            $attributes['last_check_detail'] = null;
        }

        $patch->update($attributes);

        return redirect()
            ->route('patches.index')
            ->with('status', 'Patch "'.$patch->name.'" updated.');
    }

    public function destroy(Patch $patch): RedirectResponse
    {
        $this->authorize('delete', $patch);

        // Deactivate rather than delete: deployment_patches rows reference this.
        $patch->update(['active' => false]);

        return redirect()
            ->route('patches.index')
            ->with('status', 'Patch "'.$patch->name.'" deactivated.');
    }

    /**
     * The dry-run check from section 4.5: validate a patch still applies against
     * current staging, independent of running a deployment. Useful after an
     * upstream repo updates, to catch a stale patch before it blocks a deploy.
     */
    public function check(Patch $patch, SaltClient $salt, ShimCalls $calls): RedirectResponse
    {
        $this->authorize('check', $patch);

        $result = $salt->run($calls->patchApply($patch, check: true));

        $patch->update([
            'last_checked_at' => now(),
            'last_check_ok' => $result->ok,
            'last_check_detail' => Str::limit($result->detail(), 1000),
        ]);

        return back()->with('status', $result->ok
            ? 'Patch "'.$patch->name.'" still applies cleanly.'
            : 'Patch "'.$patch->name.'" no longer applies: '.$result->detail());
    }

    private function storeFile(StorePatchRequest $request): string
    {
        $file = $request->file('patch_file');

        // Deterministic, collision-free name: the shim only ever sees the
        // basename, so it must be unique on the patches directory.
        $name = Str::slug((string) $request->validated('name')).'-'.Str::random(8).'.patch';

        return $file->storeAs('.', $name, 'patches');
    }

    /**
     * Every checkout a patch could target. A patch is written against the files as
     * they exist in one core version, so the target is a checkout rather than a
     * logical repository.
     *
     * @return Collection<int, RepositoryVersion>
     */
    private function checkoutOptions(): Collection
    {
        return RepositoryVersion::query()
            ->with(['repository', 'mediawikiVersion'])
            ->whereHas('repository', fn ($query) => $query->where('active', true))
            ->get()
            ->sortBy([
                fn (RepositoryVersion $checkout) => $checkout->repository?->name ?? '',
                fn (RepositoryVersion $checkout) => $checkout->versionLabel(),
            ])
            ->values();
    }
}
