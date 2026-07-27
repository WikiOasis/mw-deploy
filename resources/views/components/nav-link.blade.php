@props(['active' => false])

<a {{ $attributes->merge([
    'class' => $active
        ? 'border-b-2 border-slate-900 pb-0.5 font-medium text-slate-900'
        : 'border-b-2 border-transparent pb-0.5 text-slate-600 hover:text-slate-900',
]) }}>{{ $slot }}</a>
