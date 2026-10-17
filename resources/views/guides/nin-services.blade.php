<x-guest-layout
    page-title="NIN Services Guide in Nigeria"
    meta-description="BelovedSubP NIN services support guide for enrollment, correction requests, and help through WhatsApp."
    meta-keywords="nin services nigeria, nin correction, nin enrollment support, belovedsubp nin help">
    <div class="reference-guide-page max-w-4xl mx-auto px-4 py-10 space-y-6">
        <x-page-hero class="reference-guest-banner" title="NIN Services Guide" subtitle="Need NIN services in Nigeria? BelovedSubP provides fast response support through WhatsApp for NIN related requests." :battery="false" />

        <div class="rounded-3xl p-6 border border-gray-200 bg-white space-y-3">
            <h2 class="text-xl font-extrabold">Common requests</h2>
            <ul class="list-disc pl-5 space-y-2 text-sm opacity-90">
                <li>New NIN enrollment guidance</li>
                <li>NIN data correction support</li>
                <li>NIN print and verification assistance</li>
            </ul>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 bg-white">
            <p class="text-sm opacity-90">
                Use the WhatsApp support link on the homepage to chat directly and get updated steps.
            </p>
            <div class="mt-4">
                <a href="{{ route('home') }}" class="inline-flex px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-bold text-sm">
                    Open Homepage Support
                </a>
            </div>
        </div>
    </div>
</x-guest-layout>
