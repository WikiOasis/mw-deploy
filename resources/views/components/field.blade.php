@props(['label', 'name', 'hint' => null, 'required' => false])

<label class="block">
    <span class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if ($required)<span class="text-rose-600">*</span>@endif
    </span>

    <div class="mt-1">{{ $slot }}</div>

    @if ($hint !== null)
        <span class="mt-1 block text-xs text-slate-500">{{ $hint }}</span>
    @endif

    @error($name)
        <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>
    @enderror
</label>
