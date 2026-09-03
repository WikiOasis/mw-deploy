<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OidcLoginController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The console is a single-page app: the launcher, the console's own screens and
| every installed app's screens are served by one shell view and routed
| client-side, talking to routes/api.php. There is one view behind sign-in, and it
| contains nothing but the mount point.
|
| Authentication deliberately stays server-rendered. Fortify owns sign-in, the
| TOTP challenge, password resets and password confirmation, and those flows are
| worth keeping in plain HTML — an ops tool whose login page depends on a
| JavaScript bundle having loaded is an ops tool you cannot get into on the day
| the bundle is what broke.
|
| Single sign-on sits beside those flows for the same reason it is not allowed to
| replace them: an ops tool that can only be entered through a third party is one
| you cannot get into on the day the third party is what broke.
|
*/

/*
 * OpenID Connect sign-in against whichever provider the console is configured
 * for. Deliberately outside the `auth` group — the whole point is to be reachable
 * by someone who is not signed in — and registered before the SPA catch-all, as
 * well as excluded from its pattern, so neither path can be swallowed by it.
 *
 * Throttled per IP: each hit starts a round trip to the IdP and the callback does
 * an outbound token exchange, which is not something to leave unmetered on an
 * endpoint anyone can reach. Set high enough that a whole team behind one ops
 * proxy signing in after a deploy window is not the thing that trips it.
 */
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('auth/oidc/redirect', [OidcLoginController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/oidc/callback', [OidcLoginController::class, 'callback'])->name('oidc.callback');
});

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
     *
     * `user/` rather than `user`: Fortify publishes its endpoints under /user/*,
     * and excluding the bare prefix also swallowed /users — a client-side path
     * that worked until someone reloaded the page on it.
     */
    Route::get('/{path?}', SpaController::class)
        ->where('path', '^(?!api|login|logout|register|two-factor|forgot-password|reset-password|auth/oidc|user/|up|build|storage|vendor)(.*)$')
        ->name('spa');
});
