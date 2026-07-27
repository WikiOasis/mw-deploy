<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
<div class="min-h-full">
    <nav class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded bg-slate-900 text-xs font-bold text-white">mw</span>
                Deploy Portal
            </a>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <x-nav-link :href="route('deployments.index')" :active="request()->routeIs('deployments.index')">History</x-nav-link>
                <x-nav-link :href="route('versions.index')" :active="request()->routeIs('versions.*')">Versions</x-nav-link>
                <x-nav-link :href="route('repositories.index')" :active="request()->routeIs('repositories.*')">Repositories</x-nav-link>
                <x-nav-link :href="route('patches.index')" :active="request()->routeIs('patches.*')">Patches</x-nav-link>
                @can(\App\Support\Permissions::TARGETS_MANAGE)
                    <x-nav-link :href="route('targets.index')" :active="request()->routeIs('targets.*')">Targets</x-nav-link>
                @endcan
                @can(\App\Support\Permissions::USERS_MANAGE)
                    <x-nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">Users</x-nav-link>
                @endcan
            </div>

            <div class="ml-auto flex items-center gap-3 text-sm">
                @can('create', \App\Models\Deployment::class)
                    <a href="{{ route('deployments.create') }}"
                       class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700">
                        New deployment
                    </a>
                @endcan

                <a href="{{ route('two-factor.setup') }}" class="text-slate-600 hover:text-slate-900">
                    {{ auth()->user()->name }}
                    @unless (auth()->user()->hasTwoFactorEnabled())
                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">no 2FA</span>
                    @endunless
                </a>

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
