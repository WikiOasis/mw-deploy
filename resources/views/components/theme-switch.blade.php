{{--
    The appearance control for the server-rendered pages.

    Same three states and the same markup shape as the console's ThemeSwitch.vue,
    wired by `wireThemeSwitch()` in resources/js/theme.js. `aria-pressed` starts
    unset and is filled in on the first paint of that module, so the control is
    never announced as being in a state it is not.
--}}
@php
    $options = [
        ['value' => 'system', 'label' => 'Match the system appearance'],
        ['value' => 'light', 'label' => 'Light appearance'],
        ['value' => 'dark', 'label' => 'Dark appearance'],
    ];

    $paths = [
        'system' => 'M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z',
        'light' => 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
        'dark' => 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25c0 5.385 4.365 9.75 9.75 9.75 4.096 0 7.6-2.527 9.002-6.098Z',
    ];
@endphp

{{-- Concentric: 10px outer radius less the 2px inset leaves 8px inside. --}}
<div data-theme-switch role="group" aria-label="Colour theme" class="inline-flex rounded-lg border border-line bg-sunken p-0.5">
    @foreach ($options as $option)
        <button type="button"
                data-theme-option="{{ $option['value'] }}"
                aria-label="{{ $option['label'] }}"
                class="inline-flex size-8 items-center justify-center rounded-md text-fg-subtle hover:text-fg aria-pressed:bg-surface aria-pressed:text-fg aria-pressed:shadow-panel motion-safe:transition-[background-color,color] motion-safe:duration-150">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="{{ $paths[$option['value']] }}" vector-effect="non-scaling-stroke"></path>
            </svg>
        </button>
    @endforeach
</div>
