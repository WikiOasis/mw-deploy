<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatchRequest;
use App\Http\Resources\CheckoutResource;
use App\Http\Resources\PatchResource;
use App\Models\Patch;
use App\Models\RepositoryVersion;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The patch registry.
 *
 * Storing target_path and target_repo_id on the patch row is the point: an operator
 * never retypes the target directory at deploy time, which is the class of mistake
 * the CLI's `--patch`/`--patch-target` flag pair invited.
 */
final class PatchController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Patch::class);

        return response()->json([
            'data' => PatchResource::collection(
                Patch::query()
                    ->with(['targetCheckout.repository', 'targetCheckout.mediawikiVersion', 'creator'])
                    ->orderBy('active', 'desc')
                    ->orderBy('name')
                    ->get()
            )->resolve(),
            'checkouts' => CheckoutResource::collection($this->checkoutOptions())->resolve(),
            'can' => [
                'manage' => request()->user()?->can('create', Patch::class) ?? false,
            ],
        ]);
    }

    public function store(StorePatchRequest $request): JsonResponse
    {
        $patch = Patch::create([
            ...$request->safe()->except('patch_file', 'active'),
            'active' => $request->boolean('active', true),
            'file_path' => $this->storeFile($request),
            'original_filename' => $request->file('patch_file')?->getClientOriginalName(),
            'created_by' => $request->user()->getKey(),
        ]);

        $patch->load(['targetCheckout.repository', 'targetCheckout.mediawikiVersion', 'creator']);

        return response()->json([
            'patch' => (new PatchResource($patch))->resolve(),
            'message' => 'Patch "'.$patch->name.'" registered. It will be pre-selected on deployments touching its repository.',
        ], 201);
    }

    public function update(StorePatchRequest $request, Patch $patch): JsonResponse
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
        $patch->load(['targetCheckout.repository', 'targetCheckout.mediawikiVersion', 'creator']);

        return response()->json([
            'patch' => (new PatchResource($patch))->resolve(),
            'message' => 'Patch "'.$patch->name.'" updated.',
        ]);
    }

    public function destroy(Patch $patch): JsonResponse
    {
        $this->authorize('delete', $patch);

        // Deactivate rather than delete: deployment_patches rows reference this.
        $patch->update(['active' => false]);

        return response()->json(['message' => 'Patch "'.$patch->name.'" deactivated.']);
    }

    /**
     * Validate that a patch still applies against current staging, independent of
     * running a deployment. Useful after an upstream repo updates, to catch a stale
     * patch before it blocks a deploy.
     */
    public function check(Patch $patch, SaltClient $salt, ShimCalls $calls): JsonResponse
    {
        $this->authorize('check', $patch);

        $result = $salt->run($calls->patchApply($patch, check: true));

        $patch->update([
            'last_checked_at' => now(),
            'last_check_ok' => $result->ok,
            'last_check_detail' => Str::limit($result->detail(), 1000),
        ]);

        $patch->load(['targetCheckout.repository', 'targetCheckout.mediawikiVersion', 'creator']);

        return response()->json([
            'patch' => (new PatchResource($patch))->resolve(),
            'ok' => $result->ok,
            'message' => $result->ok
                ? 'Patch "'.$patch->name.'" still applies cleanly.'
                : 'Patch "'.$patch->name.'" no longer applies: '.$result->detail(),
        ]);
    }

    private function storeFile(StorePatchRequest $request): string
    {
        $file = $request->file('patch_file');

        // Deterministic, collision-free name: the shim only ever sees the basename,
        // so it must be unique on the patches directory.
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
            // One composite key: a multi-key sortBy() reads a callable as a
            // comparator taking ($a, $b) rather than as a key extractor, so a list of
            // single-argument closures would only sort by the first of them.
            ->sortBy(fn (RepositoryVersion $checkout): string => ($checkout->repository?->name ?? '')
                .'|'.$checkout->versionLabel())
            ->values();
    }
}
