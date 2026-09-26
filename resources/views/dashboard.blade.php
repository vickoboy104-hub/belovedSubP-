<x-app-layout>
    @php
        $balanceNaira = number_format(((int) ($walletBalanceKobo ?? 0)) / 100, 2);
        $referralBalanceNaira = number_format(((int) ($referralBalanceKobo ?? 0)) / 100, 2);
        $serviceTiles = [
            ['name' => 'NIN Verification', 'route' => 'vtu.nin', 'icon' => '◉'],
            ['name' => 'Print NIN Slip', 'route' => 'vtu.nin', 'icon' => '▣'],
            ['name' => 'BVN Verification', 'route' => 'vtu.bvn', 'icon' => '◉'],
            ['name' => 'BVN Services', 'route' => 'vtu.bvn', 'icon' => '▣'],
            ['name' => 'NIN Validation', 'route' => 'vtu.nin-validation', 'icon' => '✓'],
            ['name' => 'IPE Clearance', 'route' => null, 'icon' => '⌕'],
            ['name' => 'Personalization', 'route' => null, 'icon' => '◇'],
            ['name' => 'NIN Modification', 'route' => null, 'icon' => '✎'],
            ['name' => 'Fund Wallet', 'route' => 'wallet.fund', 'icon' => '₦'],
            ['name' => 'Transactions', 'route' => 'wallet.transactions', 'icon' => '↗'],
            ['name' => 'Orders', 'route' => 'vtu.orders', 'icon' => '☷'],
            ['name' => 'All Services', 'route' => 'identity.index', 'icon' => '⊞'],
        ];
        $everydayTiles = [
            ['name' => 'Data', 'route' => 'vtu.data', 'icon' => '▥'],
            ['name' => 'Airtime', 'route' => 'vtu.airtime', 'icon' => '☎'],
            ['name' => 'TV', 'route' => 'vtu.cable', 'icon' => '▣'],
            ['name' => 'Electricity', 'route' => 'vtu.electricity', 'icon' => 'ϟ'],
            ['name' => 'Education', 'route' => 'vtu.exam', 'icon' => '▤'],
            ['name' => 'Premium Apps', 'route' => 'vtu.premium-apps', 'icon' => '★'],
        ];
    @endphp

    <div class="reference-dashboard">
        <section class="reference-page-banner">
            <h1>Dashboard Overview</h1>
            <span class="reference-progress" data-device-battery role="status" aria-live="polite">Checking battery…</span>
        </section>

        <div class="reference-dashboard-body">
            <section class="reference-summary-grid" aria-label="Account summary">
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Balance (₦) <span class="reference-wallet-icon" aria-hidden="true">▣</span></div>
                    <strong class="reference-money">₦{{ $balanceNaira }}</strong>
                    <a href="{{ route('wallet.fund') }}" class="reference-full-button">Fund Wallet</a>
                </div>
                <div class="reference-summary-card reference-summary-blue">
                    <div class="reference-card-caption">Commission <span class="reference-soon">Coming Soon</span></div>
                    <strong class="reference-money">₦{{ $referralBalanceNaira }}</strong>
                    <span class="reference-full-button reference-light-button" aria-disabled="true">Invite &amp; Earn Commission</span>
                </div>
            </section>

            <section aria-labelledby="identity-title">
                <h2 class="reference-section-title" id="identity-title">Identity services</h2>
                <div class="reference-tile-grid">
                    @foreach($serviceTiles as $tile)
                        @if($tile['route'])
                            <a href="{{ route($tile['route']) }}" class="reference-service-tile">
                        @else
                            <div class="reference-service-tile reference-service-pending" aria-label="{{ $tile['name'] }} coming soon">
                        @endif
                            <span class="reference-tile-icon" aria-hidden="true">{{ $tile['icon'] }}</span>
                            <strong>{{ $tile['name'] }}</strong>
                            @unless($tile['route'])<small>Coming soon</small>@endunless
                        @if($tile['route'])</a>@else</div>@endif
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="everyday-title">
                <h2 class="reference-section-title" id="everyday-title">Subscriptions &amp; Payment Services</h2>
                <div class="reference-tile-grid">
                    @foreach($everydayTiles as $tile)
                        <a href="{{ route($tile['route']) }}" class="reference-service-tile">
                            <span class="reference-tile-icon" aria-hidden="true">{{ $tile['icon'] }}</span>
                            <strong>{{ $tile['name'] }}</strong>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="reference-recent" aria-labelledby="recent-title">
                <div class="flex items-center justify-between gap-3"><h2 class="reference-section-title" id="recent-title">Recent orders</h2><a href="{{ route('vtu.orders') }}">View all →</a></div>
                @forelse(($recentOrders ?? []) as $order)
                    <a href="{{ route('vtu.receipt', $order->id) }}" class="reference-order-row">
                        <span><strong>{{ ucwords(str_replace('_', ' ', $order->meta['type'] ?? 'Order')) }}</strong><small>{{ optional($order->created_at)->format('M j, Y') }}</small></span>
                        <span><strong>₦{{ number_format(((int) $order->amount) / 100, 2) }}</strong><small>{{ ucfirst($order->status ?? 'pending') }}</small></span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">Your orders will appear here.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
