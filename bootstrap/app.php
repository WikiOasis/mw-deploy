<?php

use App\Http\Middleware\EnsureAppAccess;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            /*
             * The SPA's API lives in the *web* middleware group, not the stateless
             * api one: it is called by a browser holding a session cookie, and it
             * therefore wants session auth, CSRF and the two-factor requirement —
             * the same protections the server-rendered pages had. There are no API
             * tokens in this application, deliberately.
             */
            Route::middleware(['web', 'auth'])
                ->prefix('api')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied across the web group: an account that can change production
        // must be TOTP-enrolled before it can reach anything but enrolment.
        $middleware->appendToGroup('web', RequireTwoFactor::class);

        $middleware->alias([
            'two-factor' => RequireTwoFactor::class,
            // Applied to each app's own route group by routes/api.php: an
            // account with no grant inside an app cannot reach any of it.
            'app.access' => EnsureAppAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
