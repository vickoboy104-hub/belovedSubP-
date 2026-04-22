<x-app-layout>
    @php
        $criticalAdminNotifications = collect($adminNotifications ?? [])->filter(function ($notification) {
            $data = is_array($notification->data ?? null) ? $notification->data : [];

            return ($data['severity'] ?? null) === 'critical' && is_null($notification->read_at);
        })->values();
        $criticalAdminNotification = $criticalAdminNotifications->first();
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">Admin Dashboard</h1>
            <p class="app-page-subtitle">Monitor users, orders, funding, profits, notifications, and system activity.</p>
        </section>

        @if($criticalAdminNotification)
            <section class="rounded-3xl border border-rose-200 bg-rose-50 p-5 shadow-[0_18px_48px_rgba(190,24,93,0.12)]">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-sm font-extrabold uppercase tracking-[0.18em] text-rose-700">Urgent Admin Warning</div>
                        <div class="mt-2 text-xl font-extrabold text-rose-900">{{ $criticalAdminNotification->data['title'] ?? 'Critical alert' }}</div>
                        <div class="mt-2 text-sm text-rose-800">{{ $criticalAdminNotification->data['message'] ?? '' }}</div>
                    </div>
                    <a href="{{ route('admin.notifications.index') }}" class="btn-primary justify-center bg-rose-600 hover:bg-rose-700">Open Alerts</a>
                </div>
            </section>
        @endif

        <section class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Users</p>
                <p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $totalUsers }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Orders</p>
                <p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $totalOrders }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Funding</p>
                <p class="mt-2 text-2xl font-extrabold text-slate-900">N{{ number_format($totalFunding / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Purchases</p>
                <p class="mt-2 text-2xl font-extrabold text-slate-900">N{{ number_format($totalPurchases / 100, 2) }}</p>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit Today</p>
                <p class="mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($todayProfit / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit This Month</p>
                <p class="mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($monthProfit / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit This Year</p>
                <p class="mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($yearProfit / 100, 2) }}</p>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Reset Totals</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="funding">
                    <button class="w-full rounded-xl bg-rose-600 px-3 py-3 text-sm font-bold text-white hover:bg-rose-700">Reset Funding Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="purchases">
                    <button class="w-full rounded-xl bg-rose-600 px-3 py-3 text-sm font-bold text-white hover:bg-rose-700">Reset Purchases Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="profit">
                    <button class="w-full rounded-xl bg-rose-600 px-3 py-3 text-sm font-bold text-white hover:bg-rose-700">Reset Profit Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="all">
                    <button class="w-full rounded-xl bg-rose-700 px-3 py-3 text-sm font-extrabold text-white hover:bg-rose-800">Reset All Totals</button>
                </form>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Admin Profit Calculator</p>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="grid grid-cols-1 gap-2 md:grid-cols-5">
                <select name="profit_period" class="input-field">
                    <option value="today" @selected(($profitFilter['period'] ?? 'today') === 'today')>Today</option>
                    <option value="month" @selected(($profitFilter['period'] ?? '') === 'month')>This Month</option>
                    <option value="date" @selected(($profitFilter['period'] ?? '') === 'date')>Specific Date</option>
                    <option value="range" @selected(($profitFilter['period'] ?? '') === 'range')>Date Range</option>
                </select>
                <input type="date" name="profit_date" value="{{ $profitFilter['date'] ?? '' }}" class="input-field">
                <input type="date" name="profit_from" value="{{ $profitFilter['from'] ?? '' }}" class="input-field">
                <input type="date" name="profit_to" value="{{ $profitFilter['to'] ?? '' }}" class="input-field">
                <button class="btn-primary justify-center">Calculate</button>
            </form>
            <div class="mt-3 text-sm text-slate-600">
                Orders: <span class="font-bold text-slate-900">{{ (int) ($profitFilterResult['orders'] ?? 0) }}</span> |
                Profit: <span class="font-bold text-emerald-700">N{{ number_format(((int) ($profitFilterResult['profit'] ?? 0)) / 100, 2) }}</span>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('admin.users') }}" class="btn-outline justify-center">Manage Users</a>
            <a href="{{ route('admin.orders') }}" class="btn-primary justify-center">View Orders</a>
            <a href="{{ route('admin.wallet.transactions') }}" class="btn-outline justify-center">Wallet Transactions</a>
            <a href="{{ route('admin.support.chats') }}" class="btn-outline justify-center">Support Chats</a>
            <a href="{{ route('admin.settings') }}" class="btn-outline justify-center">Settings</a>
            <button type="button"
                    id="openWebsiteEditorWarning"
                    class="rounded-xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white hover:bg-rose-700">
                Website Editor
            </button>
        </section>

        <div id="websiteEditorWarningOverlay" class="fixed inset-0 z-[92] hidden items-center justify-center px-4">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-lg rounded-3xl border border-red-500/30 bg-[#1a0f14] shadow-2xl overflow-hidden">
                <div class="p-6">
                    <div class="text-red-300 font-extrabold text-xl">Warning: High Impact Area</div>
                    <p class="text-red-100/90 text-sm mt-3">
                        Any change in Website Editor affects the live website immediately.
                        Do not continue unless you are sure.
                    </p>
                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button type="button"
                                id="closeWebsiteEditorWarning"
                                class="px-4 py-2 rounded-xl border border-white/15 text-white hover:bg-white/10">
                            Cancel
                        </button>
                        <a href="{{ route('admin.website-editor') }}"
                           class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold">
                            I Understand, Continue
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <section class="app-section p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Admin Notifications</h3>
                    <p class="text-sm text-slate-500 mt-1">Unread: {{ $unreadAdminNotifications }}</p>
                </div>

                @if($unreadAdminNotifications > 0)
                    <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                        @csrf
                        <button class="btn-primary">Mark All as Read</button>
                    </form>
                @endif
            </div>

            <div class="mt-4 space-y-3">
                @forelse($adminNotifications as $notification)
                    @php
                        $data = $notification->data ?? [];
                        $isUnread = is_null($notification->read_at);
                        $severity = (string) ($data['severity'] ?? 'info');
                        $isCritical = $severity === 'critical';
                    @endphp
                    <div class="rounded-2xl border {{ $isCritical ? 'border-rose-200 bg-rose-50' : ($isUnread ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-slate-50') }} p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-bold text-slate-900">{{ $data['title'] ?? 'Notification' }}</div>
                                <div class="text-sm {{ $isCritical ? 'text-rose-800' : 'text-slate-600' }} mt-1">{{ $data['message'] ?? '' }}</div>
                                <div class="text-xs text-slate-400 mt-2">{{ optional($notification->created_at)->format('d M Y, h:ia') }}</div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                @if($isCritical)
                                    <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-bold text-rose-700">Critical</span>
                                @endif
                                @if($isUnread)
                                    <span class="px-2 py-1 rounded-full text-xs font-bold {{ $isCritical ? 'bg-rose-200 text-rose-900' : 'bg-orange-100 text-slate-900' }}">Unread</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-slate-500 text-sm">No admin notifications yet.</div>
                @endforelse
            </div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="app-section p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-extrabold text-lg text-slate-900">Recent Orders</h3>
                    <a href="{{ route('admin.orders') }}" class="btn-soft">View all</a>
                </div>

                <div class="mt-4 space-y-3 md:hidden">
                    @foreach($recentOrders as $o)
                        <article class="app-record-card">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-lg font-extrabold text-slate-900">#{{ $o->id }}</div>
                                    <div class="text-sm font-semibold text-slate-700">{{ strtoupper($o->meta['type'] ?? '-') }}</div>
                                </div>
                                <div class="text-sm font-bold text-slate-900">N{{ number_format($o->amount / 100, 2) }}</div>
                            </div>
                            <div class="app-record-grid">
                                <div>
                                    <div class="app-record-label">Customer</div>
                                    <div class="app-record-value">{{ $o->customer_ref }}</div>
                                </div>
                                <div>
                                    <div class="app-record-label">Status</div>
                                    <div class="app-record-value">{{ strtoupper($o->status) }}</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-4 hidden overflow-x-auto rounded-xl border border-slate-200 md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="p-3 text-left">ID</th>
                                <th class="p-3 text-left">Type</th>
                                <th class="p-3 text-left">Customer</th>
                                <th class="p-3 text-left">Amount</th>
                                <th class="p-3 text-left">Status</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 text-slate-700">
                            @foreach($recentOrders as $o)
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3">#{{ $o->id }}</td>
                                    <td class="p-3 font-semibold">{{ strtoupper($o->meta['type'] ?? '-') }}</td>
                                    <td class="p-3">{{ $o->customer_ref }}</td>
                                    <td class="p-3 font-bold text-slate-900">N{{ number_format($o->amount / 100, 2) }}</td>
                                    <td class="p-3">{{ strtoupper($o->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="app-section p-5 sm:p-6">
                <h3 class="font-extrabold text-lg mb-4 text-slate-900">Recent Wallet Transactions</h3>

                <div class="space-y-3 md:hidden">
                    @foreach($recentTransactions as $t)
                        <article class="app-record-card">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-lg font-extrabold text-slate-900">{{ strtoupper($t->type) }}</div>
                                <div class="text-sm font-bold text-slate-900">N{{ number_format($t->amount / 100, 2) }}</div>
                            </div>
                            <div class="app-record-grid">
                                <div>
                                    <div class="app-record-label">Status</div>
                                    <div class="app-record-value">{{ strtoupper($t->status) }}</div>
                                </div>
                                <div class="col-span-1">
                                    <div class="app-record-label">Ref</div>
                                    <div class="app-record-value break-all">{{ $t->reference }}</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto rounded-xl border border-slate-200 md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="p-3 text-left">Type</th>
                                <th class="p-3 text-left">Amount</th>
                                <th class="p-3 text-left">Status</th>
                                <th class="p-3 text-left">Ref</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-200 text-slate-700">
                            @foreach($recentTransactions as $t)
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 font-semibold">{{ strtoupper($t->type) }}</td>
                                    <td class="p-3 font-bold text-slate-900">N{{ number_format($t->amount / 100, 2) }}</td>
                                    <td class="p-3">{{ strtoupper($t->status) }}</td>
                                    <td class="p-3 text-xs text-slate-500">{{ $t->reference }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    @if($criticalAdminNotification)
        <div id="adminCriticalAlertOverlay" class="fixed inset-0 z-[110] hidden items-center justify-center px-4">
            <div class="absolute inset-0 bg-black/65 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-lg overflow-hidden rounded-3xl border border-rose-200 bg-white shadow-[0_24px_60px_rgba(159,18,57,0.22)]">
                <div class="p-6">
                    <div class="text-sm font-extrabold uppercase tracking-[0.18em] text-rose-700">Critical Alert</div>
                    <div class="mt-3 text-2xl font-extrabold text-slate-900">{{ $criticalAdminNotification->data['title'] ?? 'Critical alert' }}</div>
                    <div class="mt-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm leading-6 text-rose-800">
                        {{ $criticalAdminNotification->data['message'] ?? '' }}
                    </div>
                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button type="button" id="dismissAdminCriticalAlert" class="btn-outline">Close</button>
                        <a href="{{ route('admin.notifications.index') }}" class="btn-primary justify-center bg-rose-600 hover:bg-rose-700">View Notifications</a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <script>
        (function () {
            const openBtn = document.getElementById('openWebsiteEditorWarning');
            const closeBtn = document.getElementById('closeWebsiteEditorWarning');
            const overlay = document.getElementById('websiteEditorWarningOverlay');
            const criticalOverlay = document.getElementById('adminCriticalAlertOverlay');
            const dismissCriticalBtn = document.getElementById('dismissAdminCriticalAlert');

            function openWarning() {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }

            function closeWarning() {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }

            if (openBtn && overlay) {
                openBtn.addEventListener('click', openWarning);
                closeBtn?.addEventListener('click', closeWarning);
                overlay.addEventListener('click', function (e) {
                    if (e.target === overlay) closeWarning();
                });
            }

            if (criticalOverlay) {
                if (typeof window.promoteViewportLayer === 'function') {
                    window.promoteViewportLayer(criticalOverlay);
                }

                criticalOverlay.classList.remove('hidden');
                criticalOverlay.classList.add('flex');

                const closeCritical = () => {
                    criticalOverlay.classList.add('hidden');
                    criticalOverlay.classList.remove('flex');
                };

                dismissCriticalBtn?.addEventListener('click', closeCritical);
                criticalOverlay.addEventListener('click', function (event) {
                    if (event.target === criticalOverlay) closeCritical();
                });
            }
        })();
    </script>
</x-app-layout>
