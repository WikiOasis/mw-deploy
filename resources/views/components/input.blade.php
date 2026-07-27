@props(['type' => 'text'])

<input type="{{ $type }}" {{ $attributes->merge([
    'class' => 'block w-full rounded-md border-slate-300 bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-slate-900 focus:outline-none',
]) }}>
