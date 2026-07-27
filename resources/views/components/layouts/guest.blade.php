<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-slate-100 px-4 py-12 text-slate-900 antialiased">
<div class="w-full max-w-md">
    <div class="mb-6 flex items-center justify-center gap-2 text-lg font-semibold tracking-tight">
        <span class="inline-flex h-7 w-7 items-center justify-center rounded bg-slate-900 text-xs font-bold text-white">mw</span>
        {{ config('app.name') }}
    </div>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        @if (session('status'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <ul class="mb-4 list-disc space-y-0.5 rounded-md border border-rose-200 bg-rose-50 px-4 py-2 pl-6 text-sm text-rose-900">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        {{ $slot }}
    </div>
</div>
</body>
</html>
