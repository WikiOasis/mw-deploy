{{--
    A bare input, for the rare control that is not a labelled field. Prefer
    `<x-field>`, which renders one of these and wires its label, hint and error
    up as well.
--}}
@props(['type' => 'text'])

<input type="{{ $type }}" {{ $attributes->merge(['class' => 'input-control block w-full']) }}>
