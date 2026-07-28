<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\ConfigRepositoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\DeploymentDecisionController;
use App\Http\Controllers\Api\DeploymentWizardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PatchController;
use App\Http\Controllers\Api\RepoBrowserController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal API
|--------------------------------------------------------------------------
|
| The single-page app talks to these and nothing else. They are registered
| inside the `web` middleware group (see bootstrap/app.php), so they are
| session-authenticated and CSRF-protected — there are no API tokens in this
| application, and a deploy portal is the last place to want a long-lived
| bearer token lying around in a browser.
|
| Every route is behind `auth` and RequireTwoFactor, exactly as the pages were.
|
*/

Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');
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

// Users, roles and per-repository scoping.
Route::get('users', [UserController::class, 'index'])->name('api.users.index');
Route::post('users', [UserController::class, 'store'])->name('api.users.store');
Route::put('users/{user}', [UserController::class, 'update'])->name('api.users.update');
Route::post('users/repository-scope', [UserController::class, 'scopeRepository'])->name('api.users.scope');
Route::delete('users/repository-scope/{repositoryPermission}', [UserController::class, 'unscopeRepository'])
    ->name('api.users.unscope');
