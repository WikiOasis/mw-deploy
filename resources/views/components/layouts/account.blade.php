{{--
    Chrome for the signed-in pages that are deliberately *not* part of the SPA:
    TOTP enrolment and the password form.

    They stay server-rendered because they are reachable before the two-factor
    requirement is satisfied — an account that has to enrol cannot be asked to load
    the app first — and because Fortify hands us the QR code as markup.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' — ' : '' }}{{ config('console.name') }}</title>
    <x-theme-boot />
    @vite(['resources/css/app.css', 'resources/js/auth.js'])
</head>
<body class="flex min-h-full flex-col bg-canvas text-fg antialiased">
<a href="#content"
   class="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-3 focus-visible:left-3 focus-visible:z-50 focus-visible:rounded-md focus-visible:bg-accent focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-accent-fg">
    Skip to content
</a>

<header class="sticky top-0 z-20 border-b border-line bg-surface/85 backdrop-blur-md">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6">
        <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-2 rounded-md text-sm font-semibold">
            <span class="inline-flex size-6 items-center justify-center rounded-md bg-accent text-2xs font-bold text-accent-fg" aria-hidden="true">wo</span>
            <span class="max-sm:sr-only">{{ config('console.name') }}</span>
        </a>

        <div class="ms-auto flex shrink-0 items-center gap-2">
            <x-theme-switch />

            <span class="hidden text-sm text-fg-muted sm:inline">{{ auth()->user()?->name }}</span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost">Sign out</button>
            </form>
        </div>
    </div>
</header>

<main id="content" class="mx-auto w-full max-w-7xl grow px-4 py-8 sm:px-6">
    @if (session('status'))
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-success-line bg-success-surface px-4 py-3.5 text-sm text-success-text" role="status">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m4.5 12.75 6 6 9-13.5" vector-effect="non-scaling-stroke"></path>
            </svg>
            <p class="text-pretty">{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-danger-line bg-danger-surface px-4 py-3.5 text-sm text-danger-text" role="alert">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" vector-effect="non-scaling-stroke"></path>
            </svg>
            <div class="min-w-0">
                <p class="font-medium">{{ $errors->count() === 1 ? 'There is a problem with this request:' : 'There are problems with this request:' }}</p>
                <ul class="mt-1.5 space-y-1 text-pretty">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{ $slot }}
</main>
</body>
</html>
