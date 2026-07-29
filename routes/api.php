<?php

declare(strict_types=1);

use App\Apps\AppRegistry;
use App\Apps\ConsoleApp;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console API
|--------------------------------------------------------------------------
|
| The single-page app talks to these and nothing else. They are registered
| inside the `web` middleware group (see bootstrap/app.php), so they are
| session-authenticated and CSRF-protected — there are no API tokens in this
| application, and a console that can deploy to production is the last place to
| want a long-lived bearer token lying around in a browser.
|
| Two tiers:
|
|   * the console itself — who you are, which apps you may open, and the central
|     user and access management, which is not an app and is never switched off;
|   * one group per installed app, each behind `app.access:<id>`, loaded from the
|     app registry so that adding an app to config/console.php is all it takes.
|
| Every route is behind `auth` and RequireTwoFactor, exactly as the pages were.
|
*/

Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');
Route::get('apps', AppController::class)->name('api.apps.index');

// Central user and access management: accounts, roles, and which of each app's
// permissions a role grants.
Route::get('users', [UserController::class, 'index'])->name('api.users.index');
Route::post('users', [UserController::class, 'store'])->name('api.users.store');
Route::put('users/{user}', [UserController::class, 'update'])->name('api.users.update');

Route::post('roles', [RoleController::class, 'store'])->name('api.roles.store');
Route::put('roles/{role}', [RoleController::class, 'update'])->name('api.roles.update');

/*
 * The apps.
 *
 * Each app's route file is loaded inside its access middleware, so the boundary
 * is enforced at the door rather than remembered endpoint by endpoint. An app
 * this install has disabled registers nothing at all.
 */
foreach (app(AppRegistry::class)->enabled() as $consoleApp) {
    /** @var ConsoleApp $consoleApp */
    $routes = $consoleApp->routeFile();

    if ($routes === null) {
        continue;
    }

    Route::middleware('app.access:'.$consoleApp->id())->group($routes);
}
