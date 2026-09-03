<?php

declare(strict_types=1);

use App\Apps\Deployments\DeploymentsApp;

return [

    /*
    |--------------------------------------------------------------------------
    | Console identity
    |--------------------------------------------------------------------------
    |
    | The name the chrome, the sign-in page and the browser title use. It falls
    | back to APP_NAME so an install that only sets the framework's own name
    | still reads correctly.
    |
    */

    'name' => env('CONSOLE_NAME', env('APP_NAME', 'WikiOasis Console')),

    /*
    |--------------------------------------------------------------------------
    | Break-glass password sign-in
    |--------------------------------------------------------------------------
    |
    | An install that has single sign-on working may switch the password form off
    | (see /settings/authentication). This puts it back, from the one place that
    | is reachable without signing in: the environment file on the box.
    |
    | It is for the day the identity provider is what broke — a bad client
    | secret, an expired certificate, a hostname that moved — when the console
    | still has to be usable and the setting that would fix it is behind the
    | sign-in page it is blocking. Set it, get in with a local account, put the
    | provider right, then unset it.
    |
    */

    'force_password_login' => filter_var(
        env('CONSOLE_FORCE_PASSWORD_LOGIN', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Installed apps
    |--------------------------------------------------------------------------
    |
    | The console is a shell around a set of apps. Each one is a self-contained
    | submodule: it owns its own permissions, its own API routes and its own
    | screens, and it is reachable only by an account that has been granted
    | access to it.
    |
    | Adding an app means adding its class here. Nothing else in the console
    | knows the list — the launcher, the nav, the permission admin and the API
    | route table are all built from it.
    |
    */

    'apps' => [
        DeploymentsApp::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disabled apps
    |--------------------------------------------------------------------------
    |
    | App ids to switch off for this install, comma separated. A disabled app
    | disappears from the launcher and its API routes answer 404 — useful when
    | one console serves several environments and an app is not wanted in all
    | of them, and safer than deleting the grants.
    |
    */

    'disabled_apps' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CONSOLE_DISABLED_APPS', '')),
    ))),

];
