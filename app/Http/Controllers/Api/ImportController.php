<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Import\ApplyImport;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\Discovery\ImportPlanner;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\TreeScan;
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

        $scanId = $request->string('scan')->toString();

        if ($scanId !== '') {
            return $this->pollScan($scanId, $scanner, $planner, $apply);
        }

        $versions = $this->requestedVersions($request);
        $fresh = $request->boolean('fresh');
        $scan = $fresh ? null : $scanner->cached($versions);

        // A cached scan answers instantly, no Salt call involved — the common
        // case of reopening the screen or a routine (non-"re-scan") reload.
        if ($scan !== null) {
            return $this->respondWithPlan($scan, $planner, $apply);
        }

        try {
            return $this->pollScan($scanner->startScan($versions, $fresh), $scanner, $planner, $apply);
        } catch (ScanFailed $failure) {
            return $this->scanFailedResponse($failure);
        }
    }

    public function store(Request $request, TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): JsonResponse
    {
        $this->authorize('create', Repository::class);

        $validated = $request->validate([
            'keys' => ['sometimes', 'array'],
            'keys.*' => ['string', 'max:500'],
            'fresh' => ['sometimes', 'boolean'],
            // The id of a scan already shown on the review screen. Reusing it
            // avoids re-running tree-scan a second time just to apply what the
            // first run already found; a re-derivation from a client-posted plan
            // would defeat the point of scanning at all.
            'scan_id' => ['sometimes', 'string', 'max:100'],
        ]);

        $scan = null;

        try {
            if (isset($validated['scan_id'])) {
                $scan = $scanner->pollScan($validated['scan_id']);

                if ($scan === null) {
                    return response()->json(['ok' => true, 'status' => 'pending', 'scan_id' => $validated['scan_id']]);
                }
            } else {
                $versions = $this->requestedVersions($request);
                $fresh = $request->boolean('fresh');
                $scan = $fresh ? null : $scanner->cached($versions);

                if ($scan === null) {
                    $scanId = $scanner->startScan($versions, $fresh);
                    $scan = $scanner->pollScan($scanId);

                    if ($scan === null) {
                        return response()->json(['ok' => true, 'status' => 'pending', 'scan_id' => $scanId]);
                    }
                }
            }
        } catch (ScanFailed $failure) {
            return $this->scanFailedResponse($failure);
        }

        $plan = $planner->plan($scan);
        $keys = array_values(array_map('strval', $validated['keys'] ?? []));

        $outcome = $apply($plan, $request->user(), $keys);

        $scanner->forget();

        return response()->json([
            'ok' => true,
            'status' => 'done',
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
     * Poll an in-flight or just-finished async scan and turn it into a response:
     * still-running, failed-with-a-hint, or the same plan payload show() has
     * always returned when it happens to finish inline.
     */
    private function pollScan(string $scanId, TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): JsonResponse
    {
        try {
            $scan = $scanner->pollScan($scanId);
        } catch (ScanFailed $failure) {
            return $this->scanFailedResponse($failure);
        }

        if ($scan === null) {
            return response()->json(['ok' => true, 'status' => 'pending', 'scan_id' => $scanId]);
        }

        return $this->respondWithPlan($scan, $planner, $apply, $scanId);
    }

    private function respondWithPlan(TreeScan $scan, ImportPlanner $planner, ApplyImport $apply, ?string $scanId = null): JsonResponse
    {
        $plan = $planner->plan($scan);

        // Reading the tree is also the only chance to record what it looks like, so
        // the drift columns on the repository screens are populated whether or not
        // anyone imports anything.
        $observed = $apply->recordObservations($plan);

        return response()->json([
            'ok' => true,
            'status' => 'done',
            'scan_id' => $scanId,
            'plan' => $plan->toArray(),
            'observed_checkouts' => $observed,
            'scanned_at' => now()->toIso8601String(),
        ]);
    }

    private function scanFailedResponse(ScanFailed $failure): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $failure->getMessage(),
            // Which host to go and look at — the portal's own salt CLI and the
            // shim on staging fail in ways that look alike from here.
            'hint' => $failure->hint(),
        ], 422);
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
