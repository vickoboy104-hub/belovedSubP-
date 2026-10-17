<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Cable Subscription" subtitle="Select your pay-TV provider to continue." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <x-service-outage-notice :down="$down ?? []" />

        <div class="reference-tile-grid">
            @foreach($services as $slug => $label)
                <x-service-tile :label="$label"
                                :href="route('vtu.cable.service', $slug)"
                                icon="▣" />
            @endforeach
        </div>
    </div>
</x-app-layout>
