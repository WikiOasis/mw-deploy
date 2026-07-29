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
    @vite(['resources/css/app.css', 'resources/js/auth.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
<div class="min-h-full">
    <nav class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded bg-slate-900 text-xs font-bold text-white">wo</span>
                {{ config('console.name') }}
            </a>

            <div class="ml-auto flex items-center gap-3 text-sm">
                <span class="text-slate-500">{{ auth()->user()?->name }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-500 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        @if (session('status'))
            <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-medium">There {{ $errors->count() === 1 ? 'is a problem' : 'are problems' }} with this request:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</div>
</body>
</html>
