@props([
    'logo' => null,
    'name' => null,
])

<div id="globalLoader" class="pointer-events-none fixed inset-0 z-[90] hidden items-center justify-center px-4">
    <div class="reference-loader-veil"></div>

    <div class="reference-brand-stack reference-loader-body" role="status" aria-live="polite">
        <x-brand-loader :logo="$logo" :name="$name" caption="Loading..." text-id="globalLoaderText" />
    </div>
</div>
