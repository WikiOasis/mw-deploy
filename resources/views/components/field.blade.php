{{--
    Label, control, hint and error for one field on the server-rendered pages.

    This renders the input rather than taking it in a slot, which is what lets it
    wire the field up: the hint and the error are only reachable by a screen reader
    if the control points at them, and a red border is not an error message. Every
    id here is derived from `name`, so there is nothing for a caller to remember to
    pass and nothing to get out of step.

    Anything not listed in @props lands on the input — `value`, `autocomplete`,
    `inputmode`, `autofocus`.
--}}
@props(['label', 'name', 'type' => 'text', 'hint' => null, 'required' => false])

@php
    $invalid = $errors->has($name);

    $describedBy = collect([
        $hint !== null ? $name.'-hint' : null,
        $invalid ? $name.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $name }}" class="flex items-center gap-1 text-sm font-medium text-fg">
        {{ $label }}
        @if ($required)
            {{-- The asterisk is decoration; the word is what gets announced, and
                 what someone who cannot pick a thin red glyph out of a label
                 reads instead. --}}
            <span aria-hidden="true" class="text-danger-text">*</span>
            <span class="sr-only">(required)</span>
        @endif
    </label>

    <input type="{{ $type }}"
           id="{{ $name }}"
           name="{{ $name }}"
           @if ($required) required @endif
           @if ($invalid) aria-invalid="true" @endif
           @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->merge(['class' => 'input-control mt-1.5 block w-full']) }}>

    @if ($hint !== null)
        <p id="{{ $name }}-hint" class="mt-1.5 text-xs text-pretty text-fg-subtle">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="mt-1.5 flex items-start gap-1.5 text-xs text-pretty text-danger-text">
            <svg class="mt-0.5 size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" vector-effect="non-scaling-stroke"></path>
            </svg>
            {{ $message }}
        </p>
    @enderror
</div>
