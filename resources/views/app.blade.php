{{--
    The console shell.

    Deliberately the only application view left: the launcher, the console's own
    screens and every app's screens are Vue components under resources/js/, fed by
    routes/api.php.

    The bootstrap payload is inlined so the first paint has the real user, the real
    app list and the real permissions. Without it the launcher would render empty
    and then grow tiles, and a nav that gains an "Undeploy" link a moment after you
    looked at it is worse than a nav that took another 80ms.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('console.name') }}</title>
    <x-theme-boot />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas text-fg antialiased">
    <div id="app" class="min-h-full"></div>

    <script type="application/json" id="console-bootstrap">@json($bootstrap)</script>

    <noscript>
        <div class="mx-auto max-w-2xl p-8">
            <h1 class="text-lg font-semibold">JavaScript is required</h1>
            <p class="mt-3 max-w-prose text-sm text-pretty text-fg-muted">
                The console's interface runs in the browser. Sign-in works without it, but the launcher and
                the apps behind it do not. If you need to act on the fleet right now without a working
                browser, <code class="rounded bg-sunken px-1 py-0.5 font-mono text-xs">mwdeploy-shim</code> on
                the Salt master does everything the deployments app drives.
            </p>
        </div>
    </noscript>
</body>
</html>
