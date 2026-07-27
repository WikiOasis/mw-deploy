<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeploymentDecisionController;
use App\Http\Controllers\DeploymentRollbackController;
use App\Http\Controllers\DeploymentWizardController;
use App\Http\Controllers\DeployTargetController;
use App\Http\Controllers\PatchController;
use App\Http\Controllers\PoolController;
use App\Http\Controllers\RepositoryBrowserController;
use App\Http\Controllers\RepositoryRegistryController;
use App\Http\Controllers\TwoFactorSetupController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('two-factor/setup', TwoFactorSetupController::class)->name('two-factor.setup');

    // 4.1 — repository browser (read-only for anyone with an account).
    Route::get('repositories', [RepositoryBrowserController::class, 'index'])->name('repositories.index');

    // 4.5 — registry management, gated behind repositories.manage. Declared
    // before the {repository} routes so "new" is not read as an id.
    Route::get('repositories/new', [RepositoryRegistryController::class, 'create'])->name('repositories.create');
    Route::post('repositories', [RepositoryRegistryController::class, 'store'])->name('repositories.store');

    Route::get('repositories/{repository}', [RepositoryBrowserController::class, 'show'])->name('repositories.show');
    Route::get('checkouts/{checkout}/refs', [RepositoryBrowserController::class, 'refs'])->name('checkouts.refs');
    Route::get('repositories/{repository}/edit', [RepositoryRegistryController::class, 'edit'])->name('repositories.edit');
    Route::put('repositories/{repository}', [RepositoryRegistryController::class, 'update'])->name('repositories.update');
    Route::delete('repositories/{repository}', [RepositoryRegistryController::class, 'destroy'])->name('repositories.destroy');

    // MediaWiki core versions.
    Route::get('versions', [VersionController::class, 'index'])->name('versions.index');
    Route::post('versions', [VersionController::class, 'store'])->name('versions.store');
    Route::get('versions/{version}', [VersionController::class, 'show'])->name('versions.show');
    Route::post('versions/{version}/undeploy', [VersionController::class, 'undeploy'])->name('versions.undeploy');

    // Deployment wizards. Undeploy is a separate screen, not a mode toggle:
    // removing checkouts off the whole fleet should not be one mis-click away
    // from updating them.
    Route::get('deployments/new', [DeploymentWizardController::class, 'create'])->name('deployments.create');
    Route::get('deployments/undeploy', [DeploymentWizardController::class, 'createUndeploy'])->name('deployments.undeploy');
    Route::post('deployments/review', [DeploymentWizardController::class, 'review'])->name('deployments.review');
    Route::post('deployments', [DeploymentWizardController::class, 'store'])->name('deployments.store');

    // 4.3 / 4.4 — live dashboard and history.
    Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments.index');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::get('deployments/{deployment}/state', [DeploymentController::class, 'state'])->name('deployments.state');
    Route::post('deployments/{deployment}/decision', [DeploymentDecisionController::class, 'store'])->name('deployments.decision');
    Route::post('deployments/{deployment}/rollback', [DeploymentRollbackController::class, 'store'])->name('deployments.rollback');

    // 4.5 — patch registry.
    Route::get('patches', [PatchController::class, 'index'])->name('patches.index');
    Route::get('patches/new', [PatchController::class, 'create'])->name('patches.create');
    Route::post('patches', [PatchController::class, 'store'])->name('patches.store');
    Route::get('patches/{patch}/edit', [PatchController::class, 'edit'])->name('patches.edit');
    Route::put('patches/{patch}', [PatchController::class, 'update'])->name('patches.update');
    Route::delete('patches/{patch}', [PatchController::class, 'destroy'])->name('patches.destroy');
    Route::post('patches/{patch}/check', [PatchController::class, 'check'])->name('patches.check');

    // Target inventory and manual pooling.
    Route::get('targets', [DeployTargetController::class, 'index'])->name('targets.index');
    Route::post('targets', [DeployTargetController::class, 'store'])->name('targets.store');
    Route::put('targets/{target}', [DeployTargetController::class, 'update'])->name('targets.update');
    Route::delete('targets/{target}', [DeployTargetController::class, 'destroy'])->name('targets.destroy');
    Route::post('targets/{target}/pool', [PoolController::class, 'store'])->name('targets.pool');

    // Users, roles and per-repository scoping.
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/repository-scope', [UserController::class, 'scopeRepository'])->name('users.scope');
    Route::delete('users/repository-scope/{repositoryPermission}', [UserController::class, 'unscopeRepository'])
        ->name('users.unscope');
});
