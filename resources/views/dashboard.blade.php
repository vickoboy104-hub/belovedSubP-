<x-app-layout>
    @php
        $balanceNaira = number_format(((int) ($walletBalanceKobo ?? 0)) / 100, 2);
        $referralBalanceNaira = number_format(((int) ($referralBalanceKobo ?? 0)) / 100, 2);
        $dashUser = auth()->user();
        $accountNumber = trim((string) ($dashUser?->paystack_dva_account_number ?? ''));
        $accountBank = trim((string) ($dashUser?->virtual_account_bank ?? ''));

        $identityTiles = [
            ['name' => 'NIN Verification', 'route' => 'vtu.nin', 'icon' => '◉'],
            ['name' => 'Print NIN Slip', 'route' => 'vtu.nin', 'icon' => '▣'],
            ['name' => 'BVN Verification', 'route' => 'vtu.bvn', 'icon' => '◉'],
            ['name' => 'BVN Services', 'route' => 'vtu.bvn', 'icon' => '▣'],
            ['name' => 'NIN Validation', 'route' => 'vtu.nin-validation', 'icon' => '✓'],
            ['name' => 'IPE Clearance', 'route' => null, 'icon' => '⌕'],
            ['name' => 'Personalization', 'route' => null, 'icon' => '◇'],
            ['name' => 'NIN Modification', 'route' => null, 'icon' => '✎'],
        ];
        $everydayTiles = [
            ['name' => 'Data', 'route' => 'vtu.data', 'icon' => '▥'],
            ['name' => 'Airtime', 'route' => 'vtu.airtime', 'icon' => '☎'],
            ['name' => 'TV', 'route' => 'vtu.cable', 'icon' => '▣'],
            ['name' => 'Electricity', 'route' => 'vtu.electricity', 'icon' => 'ϟ'],
            ['name' => 'Education', 'route' => 'vtu.exam', 'icon' => '▤'],
            ['name' => 'Premium Apps', 'route' => 'vtu.premium-apps', 'icon' => '★'],
        ];
        $walletTiles = [
            ['name' => 'Fund Wallet', 'route' => 'wallet.fund', 'icon' => '₦'],
            ['name' => 'Transactions', 'route' => 'wallet.transactions', 'icon' => '↗'],
            ['name' => 'Orders', 'route' => 'vtu.orders', 'icon' => '☷'],
            ['name' => 'All Services', 'route' => 'identity.index', 'icon' => '⊞'],
        ];
    @endphp

    <div class="reference-dashboard">
        <x-page-hero title="Dashboard Overview" />

        <div class="reference-dashboard-body">
            <section class="reference-summary-grid" aria-label="Account summary">
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Balance (₦) <span class="reference-wallet-icon" aria-hidden="true">▣</span></div>
                    <strong class="reference-money">₦{{ $balanceNaira }}</strong>
                    <a href="{{ route('wallet.fund') }}" class="reference-full-button">Fund Wallet</a>
                </div>
                <div class="reference-summary-card reference-summary-blue">
                    <div class="reference-card-caption">Commission (₦)</div>
                    <strong class="reference-money">₦{{ $referralBalanceNaira }}</strong>
                    <a href="{{ route('referral.index') }}" class="reference-full-button reference-light-button">Invite &amp; Earn Commission</a>
                </div>
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Account Number <span class="reference-wallet-icon" aria-hidden="true">▤</span></div>
                    @if($accountNumber !== '')
                        <strong class="reference-money reference-money-compact">{{ $accountNumber }}</strong>
                        <span class="reference-account-bank">{{ $accountBank !== '' ? $accountBank : 'Assigned bank' }}</span>
                    @else
                        <strong class="reference-money reference-money-compact">Not generated</strong>
                        <a href="{{ route('wallet.fund') }}" class="reference-full-button">Generate Account</a>
                    @endif
                </div>
            </section>

            <section aria-labelledby="identity-title">
                <h2 class="reference-section-title" id="identity-title">Identity services</h2>
                <div class="reference-tile-grid">
                    @foreach($identityTiles as $tile)
                        <x-service-tile :label="$tile['name']"
                                         :href="$tile['route'] ? route($tile['route']) : null"
                                         :icon="$tile['icon']"
                                         :pending="$tile['route'] === null" />
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="everyday-title">
                <h2 class="reference-section-title" id="everyday-title">Subscriptions &amp; Payment Services</h2>
                <div class="reference-tile-grid">
                    @foreach($everydayTiles as $tile)
                        <x-service-tile :label="$tile['name']" :href="route($tile['route'])" :icon="$tile['icon']" />
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="wallet-title">
                <h2 class="reference-section-title" id="wallet-title">Wallet &amp; Activity</h2>
                <div class="reference-tile-grid">
                    @foreach($walletTiles as $tile)
                        <x-service-tile :label="$tile['name']" :href="route($tile['route'])" :icon="$tile['icon']" />
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
