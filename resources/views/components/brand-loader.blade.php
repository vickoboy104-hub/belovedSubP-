@props([
    'logo' => null,
    'name' => null,
    'caption' => 'Loading…',
    'textId' => null,
])

{{-- The one loading visual: brand mark inside a white ring with a dark orange
     counter-arc. Shared by the boot splash and the page-transition loader. --}}
<span class="reference-brand-loader">
    <span class="reference-brand-ring" aria-hidden="true"></span>
    <span class="reference-brand-ring-accent" aria-hidden="true"></span>
    <img src="{{ $logo }}" alt="" class="reference-brand-mark" width="60" height="60">
</span>

<span class="reference-brand-caption">
    <span class="reference-brand-loading"@if($textId) id="{{ $textId }}" @endif>{{ $caption }}</span>
    @if($name)
        <span class="reference-brand-name">{{ $name }}</span>
    @endif
</span>
