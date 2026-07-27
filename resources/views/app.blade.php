{{--
    The single-page app shell.

    Deliberately the only application view left: every screen behind the sign-in
    page is a Vue component under resources/js/, fed by routes/api.php.

    The bootstrap payload is inlined so the first paint has the real user and the
    real permissions. Without it the chrome would render, then re-render once
    permissions arrived, and a nav that grows an "Undeploy" link a moment after
    you looked at it is worse than a nav that took another 80ms.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    <div id="app" class="min-h-full"></div>

    <script type="application/json" id="mwdeploy-bootstrap">@json($bootstrap)</script>

    <noscript>
        <div class="mx-auto max-w-2xl p-8">
            <h1 class="text-lg font-semibold">JavaScript is required</h1>
            <p class="mt-2 text-sm text-slate-600">
                The deploy portal's interface runs in the browser. Sign-in works without it, but the
                dashboard, wizards and live deployment view do not. If you need to act on the fleet right
                now without a working browser, <code class="font-mono">mwdeploy-shim</code> on the Salt
                master does everything the portal drives.
            </p>
        </div>
    </noscript>
</body>
</html>
