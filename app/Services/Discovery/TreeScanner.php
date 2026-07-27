<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Salt\Contracts\SaltClient;
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
            throw new ScanFailed(sprintf(
                'Could not scan %s on %s: %s',
                $root,
                $this->calls->stagingTarget(),
                $result->detail(),
            ));
        }

        if ($result->payload === null) {
            throw new ScanFailed(
                'The scan returned no JSON. Is '.config('mwdeploy.shim.binary').' installed on '
                .$this->calls->stagingTarget().' and new enough to have tree-scan?'
            );
        }

        $scan = TreeScan::fromPayload($root, $result->payload);

        Cache::put($key, $scan, self::CACHE_TTL_SECONDS);

        return $scan;
    }

    public function forget(): void
    {
        // Only the unrestricted scan is invalidated by name; the per-version keys
        // are short-lived and self-correcting.
        Cache::forget(self::CACHE_KEY.':'.md5($this->calls->scanRoot().'|'));
    }
}
