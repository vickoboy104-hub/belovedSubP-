@props([
    'logo' => null,
    'name' => null,
])

<script>document.documentElement.classList.add('splash-active');</script>

<div id="appSplash" class="reference-splash" role="status" aria-live="polite">
    <div class="reference-brand-stack">
        <x-brand-loader :logo="$logo" :name="$name" />
    </div>
</div>
