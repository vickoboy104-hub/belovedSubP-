<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Electricity Bills" subtitle="Select your distribution company to continue." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <div class="reference-tile-grid">
            @foreach($services as $slug => $label)
                <x-service-tile :label="$label"
                                :href="route('vtu.electricity.service', $slug)"
                                icon="ϟ" />
            @endforeach
        </div>
    </div>
</x-app-layout>
