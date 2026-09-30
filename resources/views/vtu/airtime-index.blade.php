<x-app-layout>
    @php
        $serviceIcons = [
            'mtn' => asset('networks/mtn.png'),
            'airtel' => asset('networks/Airtel.png'),
            'glo' => asset('networks/glo.png'),
            'etisalat' => asset('networks/9mobile.png'),
        ];
    @endphp

    <div class="reference-flow-page mx-auto max-w-5xl space-y-5">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">Buy Airtime</h1>
            <p class="app-page-subtitle">Select your network operator to continue.</p>
            <div class="app-divider mt-4"></div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach($services as $slug => $label)
                <a href="{{ route('vtu.airtime.service', $slug) }}" class="app-service-card block">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="app-service-title">{{ $label }}</div>
                            <div class="mt-2 text-sm text-slate-500">Choose this network and continue with your airtime purchase.</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="app-icon-ring h-14 w-14">
                                <img src="{{ $serviceIcons[$slug] ?? asset('images/providers/mtn.png') }}" alt="{{ $label }}" class="h-9 w-9 object-contain">
                            </div>
                            <span class="app-service-arrow" aria-hidden="true">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m9 6 6 6-6 6"></path>
                                </svg>
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
