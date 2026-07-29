<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ConfigRepositoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\DeploymentDecisionController;
use App\Http\Controllers\Api\DeploymentWizardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PatchController;
use App\Http\Controllers\Api\RepoBrowserController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\RepositoryScopeController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Deployments app API
|--------------------------------------------------------------------------
|
| The deployments app's own routes. Loaded by routes/api.php from the app
| registry, inside `app.access:deployments` — an account with no grant in this
| app cannot reach any of it, not merely the screens the launcher hides. The
| per-action policies still run underneath.
|
| Registered inside the `web` middleware group (see bootstrap/app.php), so these
| are session-authenticated, CSRF-protected and behind RequireTwoFactor. There
| are no API tokens in this application, and a deploy console is the last place
| to want a long-lived bearer token lying around in a browser.
|
| Paths are unprefixed for the same reason the app's config file is still
| config/mwdeploy.php: these URLs are in people's scripts and bookmarks, and the
| app boundary is expressed by the middleware, not by moving every endpoint.
|
*/

Route::get('dashboard', DashboardController::class)->name('api.dashboard');

// Deployments: history, the live view, and the wizard's three steps.
Route::get('deployments', [DeploymentController::class, 'index'])->name('api.deployments.index');
Route::get('deployments/wizard', [DeploymentWizardController::class, 'options'])->name('api.deployments.wizard');
Route::post('deployments/plan', [DeploymentWizardController::class, 'plan'])->name('api.deployments.plan');
Route::post('deployments', [DeploymentWizardController::class, 'store'])->name('api.deployments.store');
Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('api.deployments.show');
Route::get('deployments/{deployment}/state', [DeploymentController::class, 'state'])->name('api.deployments.state');
Route::post('deployments/{deployment}/decision', [DeploymentDecisionController::class, 'store'])
    ->name('api.deployments.decision');
Route::post('deployments/{deployment}/rollback', [DeploymentDecisionController::class, 'rollback'])
    ->name('api.deployments.rollback');
Route::post('deployments/{deployment}/cancel', [DeploymentDecisionController::class, 'cancel'])
    ->name('api.deployments.cancel');
Route::post('deployments/{deployment}/abort', [DeploymentDecisionController::class, 'abort'])
    ->name('api.deployments.abort');
Route::post('deployments/{deployment}/force-fail', [DeploymentDecisionController::class, 'forceFail'])
    ->name('api.deployments.force-fail');

// Core versions.
Route::get('versions', [VersionController::class, 'index'])->name('api.versions.index');
Route::post('versions', [VersionController::class, 'store'])->name('api.versions.store');
Route::get('versions/{version}', [VersionController::class, 'show'])->name('api.versions.show');
Route::post('versions/{version}/undeploy', [VersionController::class, 'undeploy'])->name('api.versions.undeploy');

// The repository registry. `config` and `import` are declared before
// {repository} so they are not read as an id.
Route::get('repositories', [RepositoryController::class, 'index'])->name('api.repositories.index');
Route::post('repositories', [RepositoryController::class, 'store'])->name('api.repositories.store');
Route::get('repositories/config', [ConfigRepositoryController::class, 'show'])->name('api.repositories.config');
Route::post('repositories/config', [ConfigRepositoryController::class, 'store'])->name('api.repositories.config.store');
Route::get('repositories/{repository}', [RepositoryController::class, 'show'])->name('api.repositories.show');
Route::put('repositories/{repository}', [RepositoryController::class, 'update'])->name('api.repositories.update');
Route::delete('repositories/{repository}', [RepositoryController::class, 'destroy'])->name('api.repositories.destroy');
Route::get('checkouts/{checkout}/refs', [RepositoryController::class, 'refs'])->name('api.checkouts.refs');
Route::post('checkouts/{checkout}/refs/fetch', [RepositoryController::class, 'fetchRefs'])->name('api.checkouts.refs.fetch');
Route::get('checkouts/{checkout}/tree', [RepoBrowserController::class, 'tree'])->name('api.checkouts.tree');
Route::get('checkouts/{checkout}/blob', [RepoBrowserController::class, 'blob'])->name('api.checkouts.blob');

// Adopting a farm that already exists: GET plans, POST applies.
Route::get('import', [ImportController::class, 'show'])->name('api.import.show');
Route::post('import', [ImportController::class, 'store'])->name('api.import.store');
Route::post('import/manual', [ImportController::class, 'manual'])->name('api.import.manual');

// Patches.
Route::get('patches', [PatchController::class, 'index'])->name('api.patches.index');
Route::post('patches', [PatchController::class, 'store'])->name('api.patches.store');
Route::post('patches/{patch}', [PatchController::class, 'update'])->name('api.patches.update');
Route::delete('patches/{patch}', [PatchController::class, 'destroy'])->name('api.patches.destroy');
Route::post('patches/{patch}/check', [PatchController::class, 'check'])->name('api.patches.check');

// Target inventory and manual pooling.
Route::get('targets', [TargetController::class, 'index'])->name('api.targets.index');
Route::post('targets', [TargetController::class, 'store'])->name('api.targets.store');
Route::put('targets/{target}', [TargetController::class, 'update'])->name('api.targets.update');
Route::delete('targets/{target}', [TargetController::class, 'destroy'])->name('api.targets.destroy');
Route::post('targets/{target}/pool', [TargetController::class, 'pool'])->name('api.targets.pool');

/*
 * Per-repository scoping: this app's own narrowing of who may act on which
 * repository, on top of the coarse deploy.<type> grants the console hands out.
 * It lives inside the app because it is about repositories — the console's
 * access screen deals in accounts, roles and which apps they reach.
 */
Route::get('repository-scope', [RepositoryScopeController::class, 'index'])->name('api.repository-scope.index');
Route::post('repository-scope', [RepositoryScopeController::class, 'store'])->name('api.repository-scope.store');
Route::delete('repository-scope/{repositoryPermission}', [RepositoryScopeController::class, 'destroy'])
    ->name('api.repository-scope.destroy');
