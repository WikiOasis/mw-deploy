<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Import\ApplyImport;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\Discovery\ImportPlanner;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\TreeScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adopting a MediaWiki farm the portal did not build.
 *
 * GET  reads the tree and diffs it against the registry — no writes.
 * POST writes the selected lines of that diff, and still touches nothing on disk.
 *
 * Behind repositories.manage, the same grant as adding one repository by hand:
 * importing is that decision in bulk, and it is the only registry-wide write in
 * the application.
 */
final class ImportController extends Controller
{
    public function show(Request $request, TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): JsonResponse
    {
        $this->authorize('create', Repository::class);

        try {
            $scan = $scanner->scan(
                versions: $this->requestedVersions($request),
                fresh: $request->boolean('fresh'),
            );
        } catch (ScanFailed $failure) {
            return response()->json([
                'ok' => false,
                'error' => $failure->getMessage(),
                // Which host to go and look at — the portal's own salt CLI and the
                // shim on staging fail in ways that look alike from here.
                'hint' => $failure->hint(),
            ], 422);
        }

        $plan = $planner->plan($scan);

        // Reading the tree is also the only chance to record what it looks like, so
        // the drift columns on the repository screens are populated whether or not
        // anyone imports anything.
        $observed = $apply->recordObservations($plan);

        return response()->json([
            'ok' => true,
            'plan' => $plan->toArray(),
            'observed_checkouts' => $observed,
            'scanned_at' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request, TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): JsonResponse
    {
        $this->authorize('create', Repository::class);

        $validated = $request->validate([
            'keys' => ['sometimes', 'array'],
            'keys.*' => ['string', 'max:500'],
            'fresh' => ['sometimes', 'boolean'],
        ]);

        try {
            // Re-scan rather than trusting a plan the browser posted back: the keys
            // are paths, and what they mean has to be re-derived from the tree. A
            // stale selection then simply finds nothing to do instead of writing
            // rows about a farm that has since changed.
            $scan = $scanner->scan(
                versions: $this->requestedVersions($request),
                fresh: $request->boolean('fresh'),
            );
        } catch (ScanFailed $failure) {
            return response()->json([
                'ok' => false,
                'error' => $failure->getMessage(),
                'hint' => $failure->hint(),
            ], 422);
        }

        $plan = $planner->plan($scan);
        $keys = array_values(array_map('strval', $validated['keys'] ?? []));

        $outcome = $apply($plan, $request->user(), $keys);

        $scanner->forget();

        return response()->json([
            'ok' => true,
            ...$outcome,
            'message' => $outcome['applied'] === 0
                ? 'Nothing to import — the registry already describes the tree.'
                : sprintf(
                    'Imported %d change(s): %d repository/repositories, %d checkout(s), %d version(s).',
                    $outcome['applied'],
                    $outcome['repositories'],
                    $outcome['checkouts'],
                    $outcome['versions'],
                ),
        ]);
    }

    /**
     * @return list<string>
     */
    private function requestedVersions(Request $request): array
    {
        $versions = $request->input('versions', []);

        if (is_string($versions)) {
            $versions = array_filter(explode(',', $versions));
        }

        return array_values(array_map('strval', is_array($versions) ? $versions : []));
    }
}
