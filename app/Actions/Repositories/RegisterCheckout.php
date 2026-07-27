<?php

declare(strict_types=1);

namespace App\Actions\Repositories;

use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Support\PathResolver;

/**
 * Create the repository_versions row for one checkout, without touching disk.
 *
 * The clone is a deployment step, not a side effect of a form save: it belongs on
 * the review screen with everything else, and it needs to be rollbackable. The row
 * is therefore created as `undeployed`, and the deployment that clones it flips it
 * to `present` on success.
 */
final class RegisterCheckout
{
    public function __construct(private readonly PathResolver $paths) {}

    public function __invoke(
        Repository $repository,
        ?MediaWikiVersion $version,
        RefMode $refMode = RefMode::Pinned,
        ?string $trackedRef = null,
    ): RepositoryVersion {
        $path = $this->paths->relativePath(
            $repository->type,
            $repository->name,
            $version?->version,
        );

        $trackedRef = $trackedRef === null || trim($trackedRef) === '' ? null : trim($trackedRef);

        // A pin with nothing to pin to would silently behave like Floating and
        // then fail at deploy time with a confusing message, so fall back to the
        // repository's default branch instead.
        if ($refMode === RefMode::Pinned && $trackedRef === null) {
            $trackedRef = $repository->default_branch;
        }

        $checkout = RepositoryVersion::query()->firstOrNew([
            'repository_id' => $repository->getKey(),
            'mediawiki_version_id' => $version?->getKey(),
        ]);

        $checkout->fill([
            'path' => $path,
            'ref_mode' => $refMode->value,
            'tracked_ref_type' => $trackedRef === null ? null : RefType::detect($trackedRef)->value,
            'tracked_ref_value' => $trackedRef,
        ]);

        // A brand new row starts undeployed — nothing is on disk until a
        // deployment clones it. An existing row keeps whatever presence it has, so
        // editing a pin cannot make the registry lie about what is on the servers.
        if (! $checkout->exists) {
            $checkout->status = PresenceStatus::Undeployed;
        }

        $checkout->save();

        return $checkout;
    }

    /**
     * Register a checkout only if it does not already exist, leaving an existing
     * one's pin and presence alone.
     */
    public function ensure(
        Repository $repository,
        ?MediaWikiVersion $version,
        RefMode $refMode = RefMode::Pinned,
        ?string $trackedRef = null,
    ): RepositoryVersion {
        $existing = RepositoryVersion::query()
            ->where('repository_id', $repository->getKey())
            ->where('mediawiki_version_id', $version?->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this($repository, $version, $refMode, $trackedRef);
    }
}
