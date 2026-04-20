@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge([
    'class' => 'input-field px-4 py-3 text-sm'
]) !!}>
