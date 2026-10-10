@props([
    'title' => null,
    'subtitle' => null,
    'battery' => true,
])

<section {{ $attributes->merge(['class' => 'reference-page-banner']) }} aria-label="Page heading">
    <h1>{{ $title }}</h1>

    @if($subtitle)
        <p>{{ $subtitle }}</p>
    @endif

    @if($slot->isNotEmpty() || $battery)
        <div class="reference-hero-foot">
            {{ $slot }}

            @if($battery)
                <span class="reference-progress" data-device-battery role="status" aria-live="polite">Checking battery…</span>
            @endif
        </div>
    @endif
</section>
