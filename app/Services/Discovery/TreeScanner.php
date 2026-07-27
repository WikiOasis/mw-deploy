<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltResult;
use App\Services\Salt\ShimCalls;
use Illuminate\Support\Facades\Cache;

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

    public function __construct(
        private readonly SaltClient $salt,
        private readonly ShimCalls $calls,
    ) {}

    /**
     * @param  list<string>  $versions  restrict to these core versions
     *
     * @throws ScanFailed
     */
    public function scan(array $versions = [], bool $fresh = false): TreeScan
    {
        $root = $this->calls->scanRoot();
        $key = self::CACHE_KEY.':'.md5($root.'|'.implode(',', $versions));

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
        Cache::forget(self::CACHE_KEY.':'.md5($this->calls->scanRoot().'|'));
    }
}
