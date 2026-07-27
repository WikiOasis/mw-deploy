<?php

declare(strict_types=1);

namespace App\Actions\Versions;

use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RepoAction;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\MediaWikiVersion;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Support\DeploymentOptions;
use Illuminate\Support\Facades\DB;

/**
 * Remove an entire MediaWiki core version from staging and every server.
 *
 * A line item is created for every checkout inside the version even though the
 * removal itself is a single `rm -rf versions/<ver>` per host. The line items are
 * what produce per-checkout snapshots, and those snapshots are what let the whole
 * version be rebuilt if this turns out to be a mistake.
 *
 * The runner refuses to proceed if the farm's wiki → version map still shows wikis
 * on this version, and fails closed if that map cannot be read.
 */
final class UndeployVersion
{
    /**
     * @return array{deployment: Deployment|null, error: string|null}
     */
    public function __invoke(
        User $actor,
        MediaWikiVersion $version,
        DeploymentOptions $options,
        bool $dispatch = true,
    ): array {
        if (! $version->isPresent()) {
            return ['deployment' => null, 'error' => 'Version '.$version->version.' is already undeployed.'];
        }

        $checkouts = $version->checkouts()->present()->with('repository')->get();

        $deployment = DB::transaction(function () use ($actor, $version, $options, $checkouts): Deployment {
            $deployment = Deployment::query()->create([
                'created_by' => $actor->getKey(),
                'status' => DeploymentStatus::Pending->value,
                'intent' => DeploymentIntent::VersionUndeploy->value,
                'mediawiki_version_id' => $version->getKey(),
                'options' => $options->toArray(),
            ]);

            foreach ($checkouts as $checkout) {
                /** @var RepositoryVersion $checkout */
                $deployment->repoRefs()->create([
                    'repository_version_id' => $checkout->getKey(),
                    'action' => RepoAction::Undeploy->value,
                    'ref_type' => null,
                    'ref_value' => null,
                ]);
            }

            return $deployment;
        });

        if ($dispatch) {
            RunDeployment::dispatch($deployment->getKey());
        }

        return ['deployment' => $deployment, 'error' => null];
    }
}
