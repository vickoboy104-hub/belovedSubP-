<x-app-layout>
    @php
        $serviceIcons = [
            'mtn' => asset('networks/mtn.png'),
            'airtel' => asset('networks/Airtel.png'),
            'glo' => asset('networks/glo.png'),
            'etisalat' => asset('networks/9mobile.png'),
        ];
    @endphp

    <x-page-hero class="reference-shared-banner" title="Buy Airtime" subtitle="Select your network operator to continue." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <div class="reference-tile-grid">
            @foreach($services as $slug => $label)
                <x-service-tile :label="$label"
                                :href="route('vtu.airtime.service', $slug)"
                                :image="$serviceIcons[$slug] ?? asset('networks/mtn.png')" />
            @endforeach
        </div>
    </div>
</x-app-layout>
