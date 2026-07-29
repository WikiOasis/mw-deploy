<?php

declare(strict_types=1);

namespace App\Apps\Deployments;

use App\Apps\BaseApp;

/**
 * The deployments app: MediaWiki core, extensions, skins and wiki config on
 * their way to the appserver fleet, driven through the local salt CLI.
 *
 * Everything this app does lives under `App\Services\Deployment`,
 * `App\Services\Salt`, `App\Services\Discovery` and the controllers in
 * `App\Http\Controllers\Api`; its API routes are in routes/apps/deployments.php
 * and its screens under resources/js/apps/deployments/. Its configuration is
 * config/mwdeploy.php — the app's own config file, not the console's.
 */
final class DeploymentsApp extends BaseApp
{
    public function id(): string
    {
        return 'deployments';
    }

    public function name(): string
    {
        return 'Deployments';
    }

    public function summary(): string
    {
        return 'Deploy MediaWiki core, extensions, skins and wiki config to the fleet, and watch it happen.';
    }

    public function icon(): string
    {
        return 'mw';
    }

    public function path(): string
    {
        return '/deployments';
    }

    public function routeFile(): ?string
    {
        return base_path('routes/apps/deployments.php');
    }
}
