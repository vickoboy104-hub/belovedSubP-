@props([
    'title' => null,
    'subtitle' => null,
    'crumb' => null,
    'battery' => true,
])

@php
    $crumb = $crumb ?? ($title ? Str::headline($title) : null);
@endphp

<section {{ $attributes->merge(['class' => 'reference-page-banner']) }} aria-label="Page heading">
    @if($crumb)
        <p class="reference-breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / {{ $crumb }}</p>
    @endif

    <h1>{{ $title }}</h1>

    @if($subtitle)
        <p>{{ $subtitle }}</p>
    @endif

    @if($battery)
        <span class="reference-progress" data-device-battery role="status" aria-live="polite">Checking battery…</span>
    @endif

    {{ $slot }}
</section>
