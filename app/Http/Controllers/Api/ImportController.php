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
     * Build a plan straight from pasted JSON instead of running tree-scan over
     * Salt — the fallback for when the async scan itself is what's failing
     * (a wedged minion, a farm too big for the scan's own timeout, Salt down).
     * The operator runs `mwdeploy-shim tree-scan` by hand wherever they can reach
     * the tree and pastes the output here; everything downstream (the plan, the
     * review screen, apply) is identical to a scan that finished normally.
     */
    public function manual(Request $request, TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): JsonResponse
    {
        $this->authorize('create', Repository::class);

        $validated = $request->validate([
            'payload' => ['required', 'string'],
            'root' => ['sometimes', 'string', 'max:500'],
        ]);

        $decoded = json_decode($validated['payload'], true);

        if (! is_array($decoded)) {
            return response()->json([
                'ok' => false,
                'error' => 'That is not valid JSON.',
                'hint' => 'Paste the exact output of `mwdeploy-shim tree-scan ...` — one JSON object, starting with {.',
            ], 422);
        }

        $root = trim((string) ($request->string('root')->toString() ?: ($decoded['root'] ?? '')));

        if ($root === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Missing root path.',
                'hint' => 'The pasted JSON has no "root" field — fill in the deploy root above the box instead.',
            ], 422);
        }

        $scan = TreeScan::fromPayload($root, $this->normalizeShimPayload($decoded, $root));

        if ($scan->checkouts->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'That JSON has no entries in it.',
                'hint' => 'Make sure the pasted text is a whole tree-scan result, not just part of it.',
            ], 422);
        }

        $scanner->cacheManual($scan);

        return $this->respondWithPlan($scan, $planner, $apply);
    }

    /**
     * Some fleets are still running a shim old enough to report tree-scan
     * results under `checkouts` rather than `entries` — a flat shape, absolute
     * paths, and `git`/`remote`/`ref_type` as top-level scalars instead of a
     * nested `git` object. TreeScan::fromPayload() only understands the current
     * shape, so a manually-pasted legacy result is translated here rather than
     * teaching the trusted Salt-fed path (which always sees the current shim's
     * output) to tolerate a format it will never actually receive.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeShimPayload(array $payload, string $root): array
    {
        if (isset($payload['entries'])) {
            return $payload;
        }

        if (! is_array($payload['checkouts'] ?? null)) {
            return $payload;
        }

        $root = rtrim((string) ($payload['root'] ?? $root), '/');
        $versions = [];
        $entries = [];

        foreach ($payload['checkouts'] as $checkout) {
            if (! is_array($checkout)) {
                continue;
            }

            $path = (string) ($checkout['path'] ?? '');

            // The legacy shim reports paths relative to nothing in particular —
            // usually absolute, sometimes already root-relative. Strip the root
            // prefix when present; leave it alone otherwise.
            if ($root !== '' && str_starts_with($path, $root)) {
                $path = ltrim(substr($path, strlen($root)), '/');
            } else {
                $path = ltrim($path, '/');
            }

            $kind = (string) ($checkout['kind'] ?? '');
            $version = isset($checkout['version']) ? (string) $checkout['version'] : null;

            // "refs/heads/REL1_45" / "refs/tags/x" collapse to the short name
            // ScannedCheckout/RefType already know how to read.
            $ref = (string) ($checkout['ref'] ?? '');
            $ref = preg_replace('#^refs/(heads|tags)/#', '', $ref) ?? $ref;
            $ref = $ref !== '' ? $ref : null;

            $refType = is_string($checkout['ref_type'] ?? null) ? $checkout['ref_type'] : null;

            if ($kind === 'core' && $version !== null) {
                $versions[] = $version;
            }

            // `git` is a plain boolean here ("is this a git checkout at all"),
            // unlike the current shim's nested object — everything else about
            // the remote is these flat sibling fields instead.
            $isGit = (bool) ($checkout['git'] ?? false);

            $entries[] = [
                'kind' => $kind,
                'name' => (string) ($checkout['name'] ?? ''),
                'path' => $path,
                'version' => $version,
                'is_git' => $isGit,
                'core_version' => $checkout['mw_version'] ?? $checkout['core_version'] ?? null,
                'git' => $isGit ? [
                    'url' => $checkout['remote'] ?? null,
                    'ref_type' => $refType,
                    'ref' => $ref,
                    'commit' => $checkout['commit'] ?? null,
                    'branch' => $refType === 'branch' ? $ref : null,
                ] : [],
            ];
        }

        return [
            'root' => $root !== '' ? $root : (string) ($payload['root'] ?? ''),
            'versions' => array_values(array_unique($versions)),
            'entries' => $entries,
            'warnings' => is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
            'shim_version' => is_string($payload['shim_version'] ?? null) ? $payload['shim_version'] : null,
        ];
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
