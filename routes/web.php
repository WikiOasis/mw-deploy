<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The portal is a single-page app: everything behind the sign-in page is served
| by one shell view and routed client-side, talking to routes/api.php.
|
| Authentication deliberately stays server-rendered. Fortify owns sign-in, the
| TOTP challenge, password resets and password confirmation, and those flows are
| worth keeping in plain HTML — an ops tool whose login page depends on a
| JavaScript bundle having loaded is an ops tool you cannot get into on the day
| the bundle is what broke.
|
*/

Route::middleware('auth')->group(function (): void {
    // TOTP enrolment. Server-rendered because it is reachable *before* the 2FA
    // requirement is satisfied, and because Fortify hands us the QR code as markup.
    Route::get('two-factor/setup', TwoFactorSetupController::class)->name('two-factor.setup');

    /*
     * The SPA shell, for every application path.
     *
     * The excluded prefixes are Fortify's routes, the API, the health check and
     * the built assets. A catch-all that swallowed `login` would make signing in
     * impossible, which is a bad way to find out about route ordering.
     */
    Route::get('/{path?}', SpaController::class)
        ->where('path', '^(?!api|login|logout|register|two-factor|forgot-password|reset-password|user|up|build|storage|vendor)(.*)$')
        ->name('spa');
});
