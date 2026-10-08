<x-app-layout>
    @php
        // The chart is drawn on the server from the same numbers the tiles show,
        // so it paints correctly with JavaScript switched off; the animation only
        // draws attention to a line that is already there.
        $chartWidth = 720;
        $chartHeight = 220;
        $padLeft = 10;
        $padRight = 10;
        $padTop = 18;
        $padBottom = 30;
        $plotWidth = $chartWidth - $padLeft - $padRight;
        $plotHeight = $chartHeight - $padTop - $padBottom;
        $baseY = $padTop + $plotHeight;

        $points = $series->values();
        $count = max(1, $points->count());
        $step = $count > 1 ? $plotWidth / ($count - 1) : 0;

        $coords = $points->map(function (int $kobo, int $index) use ($padLeft, $padTop, $plotHeight, $plotWidth, $step, $peakSeriesKobo, $count) {
            $x = $count > 1 ? $padLeft + ($index * $step) : $padLeft + $plotWidth;
            $y = $padTop + $plotHeight - (($kobo / $peakSeriesKobo) * $plotHeight);

            return ['x' => round($x, 1), 'y' => round($y, 1), 'kobo' => $kobo];
        })->values();

        $linePoints = $coords->map(fn ($point) => $point['x'].','.$point['y'])->implode(' ');
        $areaPath = 'M'.$coords->first()['x'].','.$baseY
            .' L'.$coords->map(fn ($point) => $point['x'].','.$point['y'])->implode(' L')
            .' L'.$coords->last()['x'].','.$baseY.' Z';

        $gridLines = [0, .25, .5, .75, 1];
    @endphp

    <x-page-hero class="reference-shared-banner"
                 title="Wallet Statistics"
                 subtitle="What every wallet holds, how the money reached it, and how the deposits are trending." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Held in wallets</p>
                <p class="admin-metric-value reference-money mt-2 font-extrabold text-slate-900">&#8358;{{ number_format($totalHeldKobo / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Money deposited</p>
                <p class="admin-metric-value reference-money mt-2 font-extrabold text-slate-900">&#8358;{{ number_format($fundedKobo / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Customers</p>
                <p class="admin-metric-value reference-money mt-2 font-extrabold text-slate-900">{{ number_format($totalUsers) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Wallets with money</p>
                <p class="admin-metric-value reference-money mt-2 font-extrabold text-slate-900">{{ number_format($fundedWallets) }}</p>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-extrabold text-slate-900">Deposits over the last {{ $windowDays }} days</h2>
                <p class="text-sm text-slate-500">{{ number_format($creditsInWindow) }} deposits &middot; &#8358;{{ number_format($koboInWindow / 100, 2) }} banked</p>
            </div>

            <svg class="app-chart mt-4" viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" role="img"
                 aria-label="Money credited to wallets each day for the last {{ $windowDays }} days">
                <defs>
                    <linearGradient id="appChartShade" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#173f74" stop-opacity=".32"></stop>
                        <stop offset="100%" stop-color="#173f74" stop-opacity="0"></stop>
                    </linearGradient>
                </defs>

                @foreach($gridLines as $fraction)
                    @php $y = round($baseY - ($fraction * $plotHeight), 1); @endphp
                    <line class="app-chart-grid" x1="{{ $padLeft }}" y1="{{ $y }}" x2="{{ $chartWidth - $padRight }}" y2="{{ $y }}"></line>
                @endforeach

                <path class="app-chart-area" d="{{ $areaPath }}"></path>
                <polyline class="app-chart-line" points="{{ $linePoints }}"></polyline>

                @foreach($coords as $point)
                    <circle class="app-chart-dot" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4">
                        <title>{{ number_format($point['kobo'] / 100, 2) }} credited</title>
                    </circle>
                @endforeach
            </svg>

            <div class="app-chart-scale">
                @foreach($series->keys() as $index => $date)
                    @if($index % 5 === 0 || $index === $series->count() - 1)
                        <span>{{ \Illuminate\Support\Carbon::parse($date)->format('j M') }}</span>
                    @endif
                @endforeach
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-extrabold text-slate-900">How the money arrived</h2>
                <p class="text-sm text-slate-500">Gross value of every deposit that reached a wallet</p>
            </div>

            <div class="mt-4 space-y-4">
                @forelse($sources as $source)
                    @php
                        $share = $fundedKobo > 0 ? ($source->kobo / $fundedKobo) * 100 : 0;
                        $relative = ($source->kobo / $largestSourceKobo) * 100;
                    @endphp
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm font-bold text-slate-800">{{ $source->label }}</span>
                            <span class="amount-fit text-sm font-extrabold text-slate-900">
                                &#8358;{{ number_format($source->kobo / 100, 2) }}
                                <span class="ml-1 text-xs font-bold text-slate-500">{{ number_format($share, 1) }}% &middot; {{ number_format($source->deposits) }}×</span>
                            </span>
                        </div>
                        <div class="app-bar-track mt-2">
                            <div class="app-bar-fill" style="--bar-w: {{ round(min(100, $relative), 2) }}%; --bar-delay: {{ $loop->index * 90 }}ms"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No deposits have reached a wallet yet.</p>
                @endforelse
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-extrabold text-slate-900">Wallets by balance</h2>
                <form method="GET" action="{{ route('admin.wallet-stats') }}" class="flex w-full items-center gap-2 sm:w-auto">
                    <input type="search" name="search" value="{{ $search }}"
                           placeholder="Name, email or phone"
                           class="input-field w-full min-w-0 sm:w-64">
                    <button type="submit" class="btn-primary justify-center px-4">Search</button>
                    @if($search !== '')
                        <a href="{{ route('admin.wallet-stats') }}" class="btn-outline justify-center px-4">Clear</a>
                    @endif
                </form>
            </div>

            <div class="mt-4 space-y-3">
                @forelse($users as $user)
                    @php
                        $balanceKobo = (int) ($user->wallet_balance_kobo ?? 0);
                        $balanceShare = $topBalanceKobo > 0 ? min(100, ($balanceKobo / $topBalanceKobo) * 100) : 0;
                        $userTrails = $trails->get($user->id, collect());
                        $userDeposits = $userTrails->sum('deposits');
                    @endphp
                    <div class="app-wallet-row">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-base font-extrabold text-slate-900">{{ $user->name }}</div>
                                <div class="truncate text-xs text-slate-500">{{ $user->email }}</div>
                            </div>
                            <div class="amount-fit shrink-0 text-right">
                                <div class="reference-money-compact font-extrabold {{ $balanceKobo > 0 ? 'text-emerald-700' : 'text-slate-400' }}">&#8358;{{ number_format($balanceKobo / 100, 2) }}</div>
                                <div class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ number_format($userDeposits) }} deposits</div>
                            </div>
                        </div>

                        <div class="app-bar-track mt-3">
                            <div class="app-bar-fill" style="--bar-w: {{ round($balanceShare, 2) }}%; --bar-delay: {{ $loop->index * 55 }}ms"></div>
                        </div>

                        <details class="app-wallet-trail">
                            <summary>How the money got there</summary>
                            <div class="mt-2 space-y-2">
                                @forelse($userTrails as $trail)
                                    <div class="app-trail-row">
                                        <span class="app-trail-label">{{ $trail->label }}</span>
                                        <span class="app-trail-amount">&#8358;{{ number_format($trail->kobo / 100, 2) }} <span class="text-slate-400">&times;{{ number_format($trail->deposits) }}</span></span>
                                    </div>
                                @empty
                                    <p class="app-trail-label">Nothing has ever been credited to this wallet.</p>
                                @endforelse
                            </div>
                        </details>

                        <a href="{{ route('admin.users.show', $user) }}" class="app-wallet-open">Open profile</a>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No customer matches that search.</p>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $users->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
