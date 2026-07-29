{{-- The Blade twin of resources/js/components/CardPanel.vue. Same shape, same
     tokens, so the TOTP pages and the console's screens agree. --}}
@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->merge(['class' => 'panel overflow-hidden']) }}>
    @if ($title !== null)
        <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-line px-5 py-3.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-fg">{{ $title }}</h2>
                @if ($subtitle !== null)
                    <p class="mt-1 max-w-prose text-xs text-pretty text-fg-subtle">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-none items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div {{ isset($flush) ? '' : 'class=p-5' }}>{{ $slot }}</div>
</section>
