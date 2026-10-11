<x-app-layout>
    @php
        // Wired services keep their own pages; everything else is the manual
        // fulfilment catalogue, so a new entry there shows up here on its own.
        $manualServices = app(\App\Services\ManualFulfilmentService::class);
        $availability = app(\App\Support\ServiceAvailability::class);

        // A paused service keeps its tile rather than vanishing from the hub: the
        // customer who was mid-job needs to see that it exists and is resting,
        // not to conclude that BelovedSubP dropped the service altogether. Only
        // the services an owner can pause are ever marked, so a tile never claims
        // to be resting over a switch that does not exist.
        $paused = fn (?string $slug): bool => $slug !== null && $availability->isDown($slug, 'identity');

        $services = [
            ['icon' => '🪪', 'title' => 'NIN verification & slips', 'copy' => 'Search by NIN, phone or personal details, then choose an available slip.', 'url' => route('vtu.nin'), 'slug' => null],
            ['icon' => '🏦', 'title' => 'BVN verification', 'copy' => 'Verify a BVN or follow the existing retrieval process.', 'url' => route('vtu.bvn'), 'slug' => null],
            ['icon' => '✓', 'title' => 'NIN validation', 'copy' => 'Submit no record or update record validation and review its progress.', 'url' => route('vtu.nin-validation'), 'slug' => 'nin_validation'],
        ];

        foreach ($manualServices->catalogue() as $slug => $manual) {
            // Entries flagged hidden_from_hub are reached from the wired NIN/BVN
            // pages above, so listing them again would double up the tile.
            if (!empty($manual['hidden_from_hub'])) {
                continue;
            }

            $services[] = [
                'icon' => $manual['icon'],
                'title' => $manual['title'],
                'copy' => $manual['summary'],
                'url' => route('vtu.manual.form', $slug),
                'slug' => $slug,
            ];
        }

        foreach ($services as $index => $service) {
            $slug = $service['slug'];
            $down = $paused($slug);

            $services[$index]['down'] = $down;
            $services[$index]['status'] = match (true) {
                $down => 'Under maintenance',
                $slug === null => 'Available',
                default => $manualServices->priceRangeLabel($slug).' • '.$manualServices->turnaroundLabel($slug),
            };
        }
    @endphp

    <div class="identity-hub mx-auto max-w-6xl space-y-7">
        <section class="identity-hero">
            <span class="identity-eyebrow">BelovedSubP • Identity services</span>
            <h1>Everything you need for identity services.</h1>
            <p>Choose a service, complete a focused request, and follow its result from your account. Your existing wallet and orders remain in one place.</p>
            <span class="reference-progress" data-device-battery role="status" aria-live="polite">Checking battery…</span>
            <div class="identity-hero-actions">
                <a href="{{ route('vtu.nin') }}" class="identity-button identity-button-white">Verify NIN <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.orders') }}" class="identity-button identity-button-outline">View activity</a>
            </div>
        </section>

        <section aria-labelledby="identity-services-title">
            <div class="identity-section-heading"><div><span class="app-kicker">Explore services</span><h2 id="identity-services-title">Identity and verification</h2></div><span class="identity-count">{{ count($services) }} services</span></div>
            <div class="identity-grid">
                @foreach($services as $service)
                    <a class="identity-tile" href="{{ $service['url'] }}">
                        <span class="identity-icon" aria-hidden="true">{{ $service['icon'] }}</span>
                        <span class="identity-status {{ $service['down'] ? 'identity-status-paused' : 'identity-status-ready' }}">{{ $service['status'] }}</span>
                        <strong>{{ $service['title'] }}</strong>
                        <span class="identity-description">{{ $service['copy'] }}</span>
                        <span class="identity-tile-action">Open service <span aria-hidden="true">→</span></span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="identity-other" aria-labelledby="other-services-title">
            <div><span class="app-kicker">More from BelovedSubP</span><h2 id="other-services-title">Everyday services</h2><p>Top up, pay bills, and manage subscriptions with your wallet.</p></div>
            <div class="identity-other-links">
                <a href="{{ route('vtu.data') }}">Data <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.airtime') }}">Airtime <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.cable') }}">TV <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.electricity') }}">Electricity <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.exam') }}">Education <span aria-hidden="true">→</span></a>
                <a href="{{ route('vtu.premium-apps') }}">Premium apps <span aria-hidden="true">→</span></a>
            </div>
        </section>
    </div>
</x-app-layout>
