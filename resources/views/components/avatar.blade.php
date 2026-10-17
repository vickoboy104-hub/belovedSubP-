@props(['user'])

@php
    $photo = $user?->avatar_url;
    $initials = $user?->initials ?? '';
@endphp

@if ($photo)
    <img {{ $attributes->merge(['class' => 'reference-avatar']) }}
         src="{{ $photo }}"
         alt="{{ trim((string) ($user?->name)) !== '' ? $user->name.' profile photo' : 'Your profile photo' }}">
@else
    <span {{ $attributes->merge(['class' => 'reference-avatar']) }} aria-hidden="true">{{ $initials }}</span>
@endif
