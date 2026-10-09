<x-app-layout>
    @php
        // Admins can add education services with slugs that have no artwork,
        // so the glyph is the fallback rather than a broken image. The paths
        // are full because JAMB's mark only exists under /exams.
        $examIcons = [
            'jamb' => '/exams/jamb.png',
            'waec' => '/images/providers/waec.png',
            'neco' => '/images/providers/neco.png',
            'nabteb' => '/images/providers/nabteb.png',
        ];
    @endphp

    <x-page-hero class="reference-shared-banner" title="Education Services" subtitle="Select the exam body you want to purchase a PIN for." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <x-service-outage-notice :down="$down ?? []" />

        <div class="reference-tile-grid">
            @foreach($services as $slug => $label)
                <x-service-tile :label="$label"
                                :href="route('vtu.exam.service', $slug)"
                                :image="isset($examIcons[$slug]) ? asset($examIcons[$slug]) : null"
                                icon="▤" />
            @endforeach
        </div>
    </div>
</x-app-layout>
