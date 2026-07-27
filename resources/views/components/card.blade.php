@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title !== null)
        <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3">
            <div>
                <h2 class="font-semibold tracking-tight">{{ $title }}</h2>
                @if ($subtitle !== null)
                    <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2 text-sm">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div {{ isset($flush) ? '' : 'class=p-5' }}>{{ $slot }}</div>
</section>
