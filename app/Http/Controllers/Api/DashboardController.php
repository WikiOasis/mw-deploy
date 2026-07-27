<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RepositoryType;
use App\Enums\TargetRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\TargetResource;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use Illuminate\Http\JsonResponse;

/**
 * The live dashboard: what is running now, what ran recently, and whether the
 * registry actually describes the farm.
 */
final class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $inFlight = [DeploymentStatus::Pending->value, DeploymentStatus::Running->value];

        $active = Deployment::query()
            ->with(['creator', 'repoRefs.repositoryVersion.repository', 'repoRefs.repositoryVersion.mediawikiVersion', 'steps'])
            ->whereIn('status', $inFlight)
            ->latest('id')
            ->get();

        $recent = Deployment::query()
            ->with(['creator', 'repoRefs.repositoryVersion.repository', 'repoRefs.repositoryVersion.mediawikiVersion', 'rollsBack', 'mediawikiVersion'])
            ->whereNotIn('status', $inFlight)
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            // Detailed, because the dashboard shows the running steps live — this
            // is the screen that replaces watching the CLI scroll.
            'active' => $active->map(
                fn (Deployment $deployment): array => (new DeploymentResource($deployment))
                    ->detailed()
                    ->resolve()
            )->all(),
            'recent' => DeploymentResource::collection($recent)->resolve(),
            'versions' => MediaWikiVersion::query()
                ->active()
                ->withCount(['checkouts as present_checkouts_count' => fn ($query) => $query
                    ->where('status', PresenceStatus::Present->value)])
                ->orderByDesc('version')
                ->get()
                ->map(fn (MediaWikiVersion $version): array => [
                    'id' => $version->getKey(),
                    'version' => $version->version,
                    'core_version' => $version->core_version,
                    'checkouts' => (int) $version->present_checkouts_count,
                ])
                ->all(),
            'targets' => TargetResource::collection(
                DeployTarget::query()->active()->orderBy('role')->orderBy('sort_order')->orderBy('hostname')->get()
            )->resolve(),
            'registry' => [
                'repositories' => Repository::query()->active()->count(),
                'checkouts' => RepositoryVersion::query()->present()->count(),
                'undeployed_checkouts' => RepositoryVersion::query()->undeployed()->count(),
                'appservers' => DeployTarget::query()->active()->role(TargetRole::Appserver)->count(),
                'proxies' => DeployTarget::query()->active()->role(TargetRole::Proxy)->count(),
                'has_config_repository' => Repository::query()->active()->ofType(RepositoryType::Config)->exists(),
                // A checkout whose pinned ref disagrees with what the last scan
                // saw on disk. Zero is the healthy answer; anything else is worth
                // a look before the next deploy moves the tree.
                'drifted_checkouts' => RepositoryVersion::query()
                    ->with('repository')
                    ->present()
                    ->whereNotNull('observed_at')
                    ->get()
                    ->filter(fn (RepositoryVersion $checkout): bool => $checkout->hasRefDrift())
                    ->count(),
            ],
        ]);
    }
}
