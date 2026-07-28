<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitFileBrowser;
use App\Services\Git\GitBrowseFailed;
use App\Services\Git\GitTreeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * File-at-commit browsing for a checkout: resolve a ref once, then list
 * directories and read files at that commit. Deliberately separate from the
 * deploy wizard's ref picker — this is read-only exploration of history, not
 * part of choosing what to deploy.
 */
final class RepoBrowserController extends Controller
{
    public function tree(Request $request, RepositoryVersion $checkout, GitFileBrowser $browser): JsonResponse
    {
        $this->authorize('view', $checkout->repository ?? Repository::class);

        $ref = trim((string) $request->query('ref', ''));
        $path = trim((string) $request->query('path', ''), '/');

        if ($ref === '') {
            return response()->json(['message' => 'A ref is required.'], 422);
        }

        try {
            $sha = $browser->resolve($checkout, $ref);
            $entries = $browser->tree($checkout, $sha, $path);
        } catch (GitBrowseFailed $failure) {
            return response()->json(['message' => $failure->getMessage()], 404);
        }

        return response()->json([
            'ref' => $ref,
            'sha' => $sha,
            'path' => $path,
            'entries' => array_map(
                fn (GitTreeEntry $entry): array => [
                    'name' => $entry->name,
                    'type' => $entry->type,
                    'mode' => $entry->mode,
                    'size' => $entry->size,
                    'path' => $path === '' ? $entry->name : $path.'/'.$entry->name,
                ],
                $entries,
            ),
        ]);
    }

    public function blob(Request $request, RepositoryVersion $checkout, GitFileBrowser $browser): JsonResponse
    {
        $this->authorize('view', $checkout->repository ?? Repository::class);

        $ref = trim((string) $request->query('ref', ''));
        $path = trim((string) $request->query('path', ''), '/');

        if ($ref === '' || $path === '') {
            return response()->json(['message' => 'A ref and a path are required.'], 422);
        }

        try {
            $sha = $browser->resolve($checkout, $ref);
            $blob = $browser->blob($checkout, $sha, $path);
        } catch (GitBrowseFailed $failure) {
            return response()->json(['message' => $failure->getMessage()], 404);
        }

        return response()->json([
            'ref' => $ref,
            'sha' => $sha,
            'path' => $path,
            'content' => $blob->content,
            'size' => $blob->size,
            'truncated' => $blob->truncated,
            'binary' => $blob->binary,
        ]);
    }
}
