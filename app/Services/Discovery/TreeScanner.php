<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltAsyncStartFailed;
use App\Services\Salt\SaltResult;
use App\Services\Salt\ShimCalls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reads an existing MediaWiki tree through the shim.
 *
 * One Salt call, no writes, and no deployment: adopting a farm has to be safe to
 * do before anything is registered, which is exactly when the portal knows least
 * about it.
 */
final class TreeScanner
{
    /**
     * Scans are cached briefly so that the review screen, a page refresh and the
     * apply step all work from the same picture of the farm. Short, because the
     * whole point is to reflect what is on disk *now* — long enough to survive an
     * operator reading the list, not long enough to hide a deployment that landed
     * while they were reading it.
     */
    private const CACHE_TTL_SECONDS = 300;

    private const CACHE_KEY = 'mwdeploy:tree-scan';

    /**
     * A tree-scan can legitimately run for most of its 1200s Salt timeout on a
     * large or NFS-backed farm — long enough that nginx or HAProxy give up on the
     * request before the portal does, which turns "still scanning" into a 504
     * with an HTML body. startScan()/pollScan() run it via `salt --async` and
     * `salt-run jobs.lookup_jid` instead, so no single HTTP request ever blocks
     * for longer than that lookup itself takes.
     */
    private const ASYNC_CACHE_PREFIX = 'mwdeploy:tree-scan:async:';

    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
    ) {}

    /**
     * The cached result of a recent scan, without starting a new one. Null means
     * there is nothing to serve instantly — the caller decides whether that's
     * worth starting a scan for.
     *
     * @param  list<string>  $versions
     */
    public function cached(array $versions = []): ?TreeScan
    {
        $cached = Cache::get($this->cacheKey($versions));

        return $cached instanceof TreeScan ? $cached : null;
    }

    /**
     * Starts a tree-scan asynchronously and returns an id to poll with
     * pollScan(). Returns immediately — only as long as it takes Salt to accept
     * the job and hand back a JID, never as long as the scan itself takes.
     *
     * @param  list<string>  $versions
     *
     * @throws ScanFailed if the local salt CLI itself never runs
     */
    public function startScan(array $versions = []): string
    {
        $inflightKey = self::ASYNC_CACHE_PREFIX.'inflight:'.md5($this->calls->scanRoot().'|'.implode(',', $versions));

        // Two concurrent cache misses (two operators opening the screen at once,
        // or a page reload racing the first load) would otherwise each start
        // their own tree-scan against the same target. A short lock collapses
        // that into one in-flight job every caller polls.
        return Cache::lock($inflightKey.':lock', 10)->block(5, function () use ($versions, $inflightKey): string {
            $existing = Cache::get($inflightKey);

            if (is_string($existing) && Cache::has(self::ASYNC_CACHE_PREFIX.$existing)) {
                return $existing;
            }

            $call = $this->calls->treeScan($this->calls->scanRoot(), $versions);

            try {
                $jid = $this->salt->startAsync($call);
            } catch (SaltAsyncStartFailed $failure) {
                throw new ScanFailed(
                    sprintf('Could not start scanning %s on %s: %s', $call->subject, $call->target, $failure->getMessage()),
                    'This failed on the portal host, not on '.$call->target.' — the local '
                        .config('mwdeploy.salt.binary').' did not run. Nothing was sent to the fleet.',
                );
            }

            $scanId = (string) Str::uuid();

            // Kept alive well past the point tree-scan's own Salt timeout plus
            // process slack would have given up, so a slow-but-eventually-successful
            // scan is never orphaned by its cache entry expiring mid-poll.
            $ttl = $call->timeoutSeconds() + (int) config('mwdeploy.salt.process_timeout_slack', 60) + 300;

            Cache::put(self::ASYNC_CACHE_PREFIX.$scanId, [
                'jid' => $jid,
                'target' => $call->target,
                'root' => $call->subject,
                'versions' => $versions,
                'deadline' => microtime(true) + $call->timeoutSeconds() + (int) config('mwdeploy.salt.process_timeout_slack', 60),
            ], $ttl);

            Cache::put($inflightKey, $scanId, $ttl);

            return $scanId;
        });
    }

    /**
     * Non-blocking check on a scan started with startScan(). Null means still
     * running — poll again later. Once finished, the result is cached under
     * both the scan id (so a repeat poll doesn't hit Salt again) and the normal
     * scan() cache key (so a plain page reload sees the same picture instantly).
     *
     * @throws ScanFailed once the scan is done and failed, or has been running
     *                    longer than tree-scan's own timeout allows
     */
    public function pollScan(string $scanId): ?TreeScan
    {
        $key = self::ASYNC_CACHE_PREFIX.$scanId;
        $record = Cache::get($key);

        if (! is_array($record)) {
            throw new ScanFailed('This scan has expired or never existed. Start a new one.');
        }

        $cachedScan = $record['scan'] ?? null;

        if ($cachedScan instanceof TreeScan) {
            return $cachedScan;
        }

        $result = $this->salt->lookupJid($record['jid'], $record['target']);

        if ($result === null) {
            if (microtime(true) > $record['deadline']) {
                Cache::forget($key);

                throw new ScanFailed(
                    sprintf('Scanning %s on %s did not finish in time.', $record['root'], $record['target']),
                    'No minion returned before the async job\'s own timeout. Check it by hand with '
                        .'`salt-run jobs.lookup_jid '.$record['jid'].'` — it may simply still be running on '
                        .$record['target'].'.',
                );
            }

            return null;
        }

        if (! $result->ok) {
            Cache::forget($key);

            throw new ScanFailed(
                sprintf('Could not scan %s on %s: %s', $record['root'], $record['target'], $result->detail()),
                $this->hintFor($result),
            );
        }

        if ($result->payload === null) {
            Cache::forget($key);

            throw new ScanFailed(
                'The scan returned no JSON from '.$record['target'].'.',
                $this->shimHint(),
            );
        }

        $scan = TreeScan::fromPayload($record['root'], $result->payload);

        $record['scan'] = $scan;
        Cache::put($key, $record, self::CACHE_TTL_SECONDS);
        Cache::put($this->cacheKey($record['versions']), $scan, self::CACHE_TTL_SECONDS);

        return $scan;
    }

    /**
     * @param  list<string>  $versions  restrict to these core versions
     *
     * @throws ScanFailed
     */
    public function scan(array $versions = [], bool $fresh = false): TreeScan
    {
        $root = $this->calls->scanRoot();
        $key = $this->cacheKey($versions);

        if (! $fresh) {
            $cached = Cache::get($key);

            if ($cached instanceof TreeScan) {
                return $cached;
            }
        }

        $result = $this->salt->run($this->calls->treeScan($root, $versions));

        if (! $result->ok) {
            throw new ScanFailed(
                sprintf(
                    'Could not scan %s on %s: %s',
                    $root,
                    $this->calls->stagingTarget(),
                    $result->detail(),
                ),
                $this->hintFor($result),
            );
        }

        if ($result->payload === null) {
            throw new ScanFailed(
                'The scan returned no JSON from '.$this->calls->stagingTarget().'.',
                $this->shimHint(),
            );
        }

        $scan = TreeScan::fromPayload($root, $result->payload);

        Cache::put($key, $scan, self::CACHE_TTL_SECONDS);

        return $scan;
    }

    /**
     * Which host to go and look at.
     *
     * A scan crosses two machines, and the failure modes look alike from the
     * outside: a salt CLI that would not start on the portal host produces the same
     * "no usable output" as a shim that is missing on staging. Saying "check the
     * shim on staging" when the portal's own CLI never ran wastes the first ten
     * minutes of an incident.
     */
    private function hintFor(SaltResult $result): string
    {
        $detail = $result->detail();

        $localFailure = $result->retcode === 64
            || str_contains($detail, 'The local salt CLI refused to run')
            || str_contains($detail, 'Could not start [');

        if ($localFailure) {
            return 'This failed on the portal host, not on '.$this->calls->stagingTarget()
                .' — the local '.config('mwdeploy.salt.binary').' did not run. Nothing was sent to the fleet.';
        }

        // Distinct from a missing/outdated shim: Salt itself got no return from
        // any minion before its own --timeout expired. Pointing at the shim
        // version here would send an operator chasing an install that is not
        // the problem — check that the minion is up and reachable first.
        if (str_contains($detail, 'No return received') || str_contains($detail, 'Minion did not return')) {
            return $this->calls->stagingTarget().' did not respond to Salt before the timeout — this is a '
                .'minion connectivity problem, not the shim. Confirm the minion is up and check in '
                .'(`salt-run manage.status`), and look up the job with `salt-run jobs.lookup_jid <jid>` '
                .'once it finishes.';
        }

        return $this->shimHint();
    }

    private function shimHint(): string
    {
        return 'The scan runs `'.config('mwdeploy.shim.binary').' tree-scan` on '
            .$this->calls->stagingTarget().'. Check that the shim is installed there and is at least version 2.1.0.';
    }

    public function forget(): void
    {
        // Only the unrestricted scan is invalidated by name; the per-version keys
        // are short-lived and self-correcting.
        Cache::forget($this->cacheKey());
    }

    /**
     * @param  list<string>  $versions
     */
    private function cacheKey(array $versions = []): string
    {
        return self::CACHE_KEY.':'.md5($this->calls->scanRoot().'|'.implode(',', $versions));
    }
}
