@props([
    'logo' => null,
    'name' => null,
])

<script>document.documentElement.classList.add('splash-active');</script>

<div id="appSplash" class="reference-splash" role="status" aria-live="polite">
    <div class="reference-splash-icon">
        <span class="reference-splash-loader">
            <span class="reference-splash-ring"></span>
            <img src="{{ $logo }}" alt="" class="reference-splash-logo">
        </span>
        <span class="reference-splash-caption">
            <span class="reference-splash-loading">Loading…</span>
            <span class="reference-splash-app">{{ $name }}</span>
        </span>
    </div>
</div>
