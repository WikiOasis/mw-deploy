<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\TargetRole;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Repository;
use Illuminate\Contracts\View\View;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $active = Deployment::query()
            ->with(['creator', 'repoRefs.repository'])
            ->whereIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])
            ->latest('id')
            ->get();

        return view('dashboard', [
            'active' => $active,
            'recent' => Deployment::query()
                ->with(['creator', 'repoRefs.repository', 'rollsBack'])
                ->whereNotIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])
                ->latest('id')
                ->limit(10)
                ->get(),
            'repositoryCount' => Repository::query()->active()->count(),
            'appserverCount' => DeployTarget::query()->active()->role(TargetRole::Appserver)->count(),
            'proxyCount' => DeployTarget::query()->active()->role(TargetRole::Proxy)->count(),
        ]);
    }
}
