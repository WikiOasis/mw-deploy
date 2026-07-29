<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' — ' : '' }}{{ config('console.name') }}</title>
    <x-theme-boot />
    {{-- Deliberately not the SPA bundle: these are the pages you need on the day
         the application bundle is what broke. --}}
    @vite(['resources/css/app.css', 'resources/js/auth.js'])
</head>
<body class="flex min-h-full flex-col bg-canvas text-fg antialiased">
{{--
    A quiet wash of the accent behind the card, gone well before the fold. It is
    the only decorative thing on the sign-in page, and it is what keeps one centred
    box on a flat background from reading like an error page.
--}}
<div class="pointer-events-none fixed inset-x-0 top-0 h-72 bg-gradient-to-b from-accent-subtle to-transparent" aria-hidden="true"></div>

<main class="relative flex grow flex-col items-center justify-center px-4 py-12">
    <div class="w-full max-w-sm">
        <div class="mb-7 flex flex-col items-center gap-3">
            <span class="inline-flex size-10 items-center justify-center rounded-xl bg-accent text-xs font-bold text-accent-fg shadow-panel" aria-hidden="true">wo</span>
            <h1 class="text-lg font-semibold">{{ config('console.name') }}</h1>
        </div>

        <div class="panel p-6">
            @if (session('status'))
                <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-success-line bg-success-surface px-3 py-2.5 text-sm text-success-text" role="status">
                    <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m4.5 12.75 6 6 9-13.5" vector-effect="non-scaling-stroke"></path>
                    </svg>
                    <p class="text-pretty">{{ session('status') }}</p>
                </div>
            @endif

            {{-- Fortify reports a failed sign-in as a validation error on `email`,
                 so this is the summary for a form whose fields mark themselves as
                 well. `role="alert"` because it is the answer to a submit that was
                 just made. --}}
            @if ($errors->any())
                <div class="mb-5 flex items-start gap-2.5 rounded-lg border border-danger-line bg-danger-surface px-3 py-2.5 text-sm text-danger-text" role="alert">
                    <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" vector-effect="non-scaling-stroke"></path>
                    </svg>
                    <ul class="space-y-1 text-pretty">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>

        <div class="mt-6 flex justify-center">
            <x-theme-switch />
        </div>
    </div>
</main>
</body>
</html>
