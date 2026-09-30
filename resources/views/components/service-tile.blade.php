@props([
    'label' => null,
    'href' => null,
    'icon' => null,
    'badge' => null,
    'pending' => false,
])

@php
    $tag = $pending || !$href ? 'div' : 'a';
    $classes = 'reference-service-tile' . (($pending || !$href) ? ' reference-service-pending' : '');
@endphp

<{{ $tag }}
    @if($tag === 'a') href="{{ $href }}" @endif
    @if($tag === 'div') aria-label="{{ $label }} coming soon" @endif
    {{ $attributes->merge(['class' => $classes]) }}>
    @if($badge)
        <span class="reference-tile-badge">{{ $badge }}</span>
    @endif
    <span class="reference-tile-icon" aria-hidden="true">{{ $icon }}</span>
    <strong>{{ $label }}</strong>
    @if($pending || !$href)
        <small>Coming soon</small>
    @endif
    {{ $slot }}
</{{ $tag }}>
