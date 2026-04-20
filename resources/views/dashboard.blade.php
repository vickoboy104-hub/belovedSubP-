<x-app-layout>
    @php
        $balanceNaira = number_format(((int) ($walletBalanceKobo ?? 0)) / 100, 2);
        $referralBalanceNaira = number_format(((int) ($referralBalanceKobo ?? 0)) / 100, 2);
        $referralTotalNaira = number_format(((int) ($referralTotalKobo ?? 0)) / 100, 2);
        $authUser = auth()->user();
    @endphp

    <div class="space-y-8">
        <section>
            <h1 class="app-page-title">Dashboard</h1>
            <p class="app-page-subtitle">Manage your wallet, launch services quickly and track recent activity from one place.</p>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_420px]">
            <div class="space-y-6">
                <div class="app-section p-6 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="app-kicker">Available Balance</div>
                            <div class="mt-3 text-4xl font-extrabold text-slate-900">&#8358;{{ $balanceNaira }}</div>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-500">
                                Fund your wallet by transfer or checkout and use it across data, airtime, utility bills and identity services.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <a href="{{ route('wallet.fund') }}" class="btn-primary">Fund Wallet</a>
                            <a href="{{ route('vtu.orders') }}" class="btn-outline">View Orders</a>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-3">
                        <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold text-slate-500">Bank</div>
                            <div class="mt-2 text-sm font-extrabold text-slate-900">{{ $authUser?->virtual_account_bank ?: '-' }}</div>
                        </div>
                        <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold text-slate-500">Account Number</div>
                            <div class="mt-2 text-sm font-extrabold tracking-wide text-slate-900">{{ $authUser?->virtual_account_number ?: '-' }}</div>
                        </div>
                        <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold text-slate-500">Account Name</div>
                            <div class="mt-2 text-sm font-extrabold text-slate-900">{{ $authUser?->virtual_account_name ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <div class="app-section p-6 sm:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="app-kicker">Quick Access</div>
                            <h2 class="mt-2 text-2xl font-extrabold text-slate-900">Service shortcuts</h2>
                        </div>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        <a href="{{ route('vtu.data') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">📶</span>
                            <span class="app-mini-tile-label">Data</span>
                        </a>
                        <a href="{{ route('vtu.airtime') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">📞</span>
                            <span class="app-mini-tile-label">Airtime</span>
                        </a>
                        <a href="{{ route('vtu.cable') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">📺</span>
                            <span class="app-mini-tile-label">TV</span>
                        </a>
                        <a href="{{ route('vtu.electricity') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">⚡</span>
                            <span class="app-mini-tile-label">Electricity</span>
                        </a>
                        <a href="{{ route('vtu.exam') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">🎓</span>
                            <span class="app-mini-tile-label">Education</span>
                        </a>
                        <a href="{{ route('vtu.recharge-card') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">💳</span>
                            <span class="app-mini-tile-label">Recharge PIN</span>
                        </a>
                        <a href="{{ route('vtu.premium-apps') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">⭐</span>
                            <span class="app-mini-tile-label">Premium Apps</span>
                        </a>
                        <a href="{{ route('vtu.nin') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">🪪</span>
                            <span class="app-mini-tile-label">NIN</span>
                        </a>
                        <a href="{{ route('wallet.transactions') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">🧾</span>
                            <span class="app-mini-tile-label">Transactions</span>
                        </a>
                        <a href="{{ route('support.bot') }}" class="app-mini-tile">
                            <span class="app-mini-tile-icon">💬</span>
                            <span class="app-mini-tile-label">Support</span>
                        </a>
                    </div>
                </div>

                <div class="app-section p-6 sm:p-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="app-kicker">Recent Orders</div>
                            <h2 class="mt-2 text-2xl font-extrabold text-slate-900">Latest transactions</h2>
                        </div>
                        <a href="{{ route('vtu.orders') }}" class="btn-soft">View All</a>
                    </div>

                    <div class="mt-6 overflow-x-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-slate-500">
                                    <th class="py-3 pr-4">Date</th>
                                    <th class="py-3 pr-4">Type</th>
                                    <th class="py-3 pr-4">Customer</th>
                                    <th class="py-3 pr-4">Amount</th>
                                    <th class="py-3 pr-4">Status</th>
                                    <th class="py-3 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody class="text-slate-700">
                                @forelse(($recentOrders ?? []) as $o)
                                    @php
                                        $type = $o->meta['type'] ?? 'order';
                                        $amountN = number_format(((int) $o->amount) / 100, 2);
                                        $status = $o->status ?? 'pending';
                                    @endphp
                                    <tr class="border-b border-slate-100">
                                        <td class="py-4 pr-4">{{ optional($o->created_at)->format('d M, Y h:ia') }}</td>
                                        <td class="py-4 pr-4 capitalize">{{ str_replace('-', ' ', $type) }}</td>
                                        <td class="py-4 pr-4">{{ $o->customer_ref }}</td>
                                        <td class="py-4 pr-4">&#8358;{{ $amountN }}</td>
                                        <td class="py-4 pr-4">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                                                {{ $status === 'success' ? 'bg-emerald-50 text-emerald-700' : ($status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">
                                                {{ strtoupper($status) }}
                                            </span>
                                        </td>
                                        <td class="py-4 pr-4">
                                            <a href="{{ route('vtu.receipt', $o->id) }}" class="font-bold text-slate-900 hover:underline">Receipt</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-6 text-center text-slate-500">No recent orders yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="app-section-muted p-6">
                    <div class="app-kicker">Referral Wallet</div>
                    <div class="mt-3 text-3xl font-extrabold text-slate-900">&#8358;{{ $referralBalanceNaira }}</div>
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                            <div class="text-xs text-slate-500">Total Earned</div>
                            <div class="mt-2 text-lg font-extrabold text-slate-900">&#8358;{{ $referralTotalNaira }}</div>
                        </div>
                        <div class="rounded-[20px] border border-slate-200 bg-white p-4">
                            <div class="text-xs text-slate-500">Active Referrals</div>
                            <div class="mt-2 text-lg font-extrabold text-slate-900">{{ (int) ($activeReferrals ?? 0) }}</div>
                        </div>
                    </div>
                </div>

                <div class="app-section p-6">
                    <div class="app-kicker">Notifications</div>
                    <div class="mt-4 space-y-3">
                        @forelse(($userNotifications ?? []) as $notification)
                            @php
                                $ndata = $notification->data ?? [];
                            @endphp
                            <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4">
                                <div class="font-bold text-slate-900">{{ $ndata['title'] ?? 'Notification' }}</div>
                                <div class="mt-1 text-sm leading-6 text-slate-500">{{ $ndata['message'] ?? '' }}</div>
                            </div>
                        @empty
                            <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No notifications yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
