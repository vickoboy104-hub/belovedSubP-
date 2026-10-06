<x-app-layout>
    @php
        $balanceNaira = number_format(((int) ($walletBalanceKobo ?? 0)) / 100, 2);
        $referralBalanceNaira = number_format(((int) ($referralBalanceKobo ?? 0)) / 100, 2);
        $dashUser = auth()->user();
        $accountMeta = (array) ($dashUser?->virtual_account_metadata ?? []);
        $accountNumber = trim((string) ($dashUser?->virtual_account_number ?? ''));
        $accountBank = trim((string) ($dashUser?->virtual_account_bank ?? ''));
        $accountIsTemporary = false;

        // A customer who only ever generated a one-time account still needs to see it here.
        if ($accountNumber === '') {
            $temporary = (array) ($accountMeta['temporary_virtual_account'] ?? []);
            $temporaryNumber = trim((string) ($temporary['account_number'] ?? ''));

            if ($temporaryNumber !== '') {
                $expiresAt = null;
                try {
                    $expiresAt = \Illuminate\Support\Carbon::parse((string) ($temporary['expires_at'] ?? ''));
                } catch (\Throwable $e) {
                    $expiresAt = null;
                }

                if ($expiresAt === null || $expiresAt->isFuture()) {
                    $accountNumber = $temporaryNumber;
                    $accountBank = trim((string) ($temporary['bank_name'] ?? ''));
                    $accountIsTemporary = true;
                }
            }
        }

        // Key-less identity services are handled by the manual fulfilment queue,
        // so every tile here is a live link.
        $identityTiles = [
            ['name' => 'NIN Verification', 'url' => route('vtu.nin'), 'icon' => '◉'],
            ['name' => 'Print NIN Slip', 'url' => route('vtu.manual.form', 'nin_slip_print'), 'icon' => '▣'],
            ['name' => 'BVN Verification', 'url' => route('vtu.bvn'), 'icon' => '◉'],
            ['name' => 'BVN Services', 'url' => route('vtu.bvn'), 'icon' => '▣'],
            ['name' => 'NIN Validation', 'url' => route('vtu.nin-validation'), 'icon' => '✓'],
            ['name' => 'IPE Clearance', 'url' => route('vtu.manual.form', 'ipe_clearance'), 'icon' => '⌕'],
            ['name' => 'Personalization', 'url' => route('vtu.manual.form', 'nin_personalization'), 'icon' => '◇'],
            ['name' => 'NIN Modification', 'url' => route('vtu.manual.form', 'nin_modification'), 'icon' => '✎'],
            ['name' => 'NIN Delink', 'url' => route('vtu.manual.form', 'nin_delink'), 'icon' => '⛓'],
            ['name' => 'NIN Agreement', 'url' => route('vtu.manual.form', 'nin_agreement'), 'icon' => '📄'],
            ['name' => 'Print BVN Slip', 'url' => route('vtu.manual.form', 'bvn_print'), 'icon' => '🖨'],
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
        <x-page-hero title="Dashboard Overview">
            <div class="reference-hero-user">
                <x-avatar :user="$dashUser" class="reference-hero-avatar" />
                <span class="reference-hero-greet" data-hero-greeting="{{ $dashUser?->first_name }}">{{ $dashUser?->first_name ? 'Welcome back, '.$dashUser->first_name : 'Welcome back' }}</span>
            </div>
        </x-page-hero>

        <div class="reference-dashboard-body">
            <section class="reference-summary-grid" aria-label="Account summary">
                <div class="reference-summary-card">
                    <div class="reference-card-caption">Balance (₦) <span class="reference-wallet-icon" aria-hidden="true">▣</span></div>
                    <strong class="reference-money" id="walletBalance" data-wallet-kobo="{{ (int) ($walletBalanceKobo ?? 0) }}">₦{{ $balanceNaira }}</strong>
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
                        <span class="reference-account-bank">
                            {{ $accountBank !== '' ? $accountBank : 'Assigned bank' }}
                            @if($accountIsTemporary)
                                &middot; one-time, expires soon
                            @endif
                        </span>
                        <button type="button" class="reference-full-button" data-copy-text="{{ $accountNumber }}" data-copy-label="Copy Account" data-copy-done="Copied">Copy Account</button>
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
                        <x-service-tile :label="$tile['name']" :href="$tile['url']" :icon="$tile['icon']" />
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

            <section class="reference-recent" aria-labelledby="alerts-title">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="reference-section-title" id="alerts-title">Payment alerts</h2>
                    <a href="{{ route('notifications.index') }}">
                        All alerts
                        @if((int) ($unreadUserNotifications ?? 0) > 0)
                            &middot; {{ (int) $unreadUserNotifications }} new
                        @endif
                        &rarr;
                    </a>
                </div>

                @forelse(($userNotifications ?? []) as $alert)
                    @php
                        $alertData = (array) ($alert->data ?? []);
                        $alertAmountKobo = (int) ($alertData['amount_kobo'] ?? 0);
                        $alertUrl = trim((string) ($alertData['url'] ?? ''));
                        $isCredit = (string) ($alertData['type'] ?? '') === 'credit';
                    @endphp
                    <a href="{{ $alertUrl !== '' ? $alertUrl : route('notifications.index') }}" class="reference-order-row">
                        <span>
                            <strong>{{ $alertData['title'] ?? 'Wallet alert' }}</strong>
                            <small>{{ $alertData['message'] ?? '' }}</small>
                        </span>
                        <span>
                            <strong class="{{ $isCredit ? 'text-emerald-700' : '' }}">
                                {{ $alertAmountKobo > 0 ? ($isCredit ? '+' : '-').'₦'.number_format($alertAmountKobo / 100, 2) : '' }}
                            </strong>
                            <small>{{ optional($alert->created_at)->format('M j, Y') }}{{ is_null($alert->read_at) ? ' · new' : '' }}</small>
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-slate-500">
                        Every payment into your wallet is confirmed here and by email as soon as it arrives. Nothing to report yet.
                    </p>
                @endforelse
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
