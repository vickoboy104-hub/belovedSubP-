<x-app-layout>
    @php
        $criticalAdminNotifications = collect($adminNotifications ?? [])->filter(function ($notification) {
            $data = is_array($notification->data ?? null) ? $notification->data : [];

            return ($data['severity'] ?? null) === 'critical' && is_null($notification->read_at);
        })->values();
        $criticalAdminNotification = $criticalAdminNotifications->first();
    @endphp

    <x-page-hero class="reference-shared-banner" title="Admin Dashboard" subtitle="Monitor users, orders, funding, profits, notifications, and system activity." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        @if($criticalAdminNotification)
            <section class="app-note-card is-critical">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <span class="app-flag app-flag-critical">Urgent Admin Warning</span>
                        <div class="mt-2 text-xl font-extrabold">{{ $criticalAdminNotification->data['title'] ?? 'Critical alert' }}</div>
                        <div class="mt-2 text-sm opacity-80">{{ $criticalAdminNotification->data['message'] ?? '' }}</div>
                    </div>
                    <a href="{{ route('admin.notifications.index') }}" class="btn-danger shrink-0">Open Alerts</a>
                </div>
            </section>
        @endif

        <section class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Users</p>
                <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">{{ $totalUsers }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Orders</p>
                <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">{{ $totalOrders }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Funding</p>
                <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">N{{ number_format($totalFunding / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total Purchases</p>
                <p class="admin-metric-value mt-2 text-2xl font-extrabold text-slate-900">N{{ number_format($totalPurchases / 100, 2) }}</p>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit Today</p>
                <p class="admin-metric-value mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($todayProfit / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit This Month</p>
                <p class="admin-metric-value mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($monthProfit / 100, 2) }}</p>
            </div>
            <div class="app-section p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profit This Year</p>
                <p class="admin-metric-value mt-2 text-xl font-extrabold text-emerald-700">N{{ number_format($yearProfit / 100, 2) }}</p>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <p class="text-sm font-semibold text-slate-600 mb-3">Reset Totals</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="funding">
                    <button class="btn-danger w-full">Reset Funding Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="purchases">
                    <button class="btn-danger w-full">Reset Purchases Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="profit">
                    <button class="btn-danger w-full">Reset Profit Total</button>
                </form>
                <form method="POST" action="{{ route('admin.metrics.reset') }}">
                    @csrf
                    <input type="hidden" name="metric" value="all">
                    <button class="btn-danger w-full">Reset All Totals</button>
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
                Profit: <span class="amount-fit inline-block font-bold text-emerald-700">N{{ number_format(((int) ($profitFilterResult['profit'] ?? 0)) / 100, 2) }}</span>
            </div>
        </section>

        <section class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('admin.users') }}" class="btn-outline justify-center">Manage Users</a>
            <a href="{{ route('admin.orders') }}" class="btn-primary justify-center">View Orders</a>
            <a href="{{ route('admin.wallet.transactions') }}" class="btn-outline justify-center">Wallet Transactions</a>
            <a href="{{ route('admin.support.chats') }}" class="btn-outline justify-center">Support Chats</a>
            <a href="{{ route('admin.broadcast') }}" class="btn-outline justify-center">Announcements</a>
            <a href="{{ route('admin.settings') }}" class="btn-outline justify-center">Settings</a>
            <button type="button"
                    id="openWebsiteEditorWarning"
                    class="btn-outline justify-center">
                Website Editor
            </button>
        </section>

        <div id="websiteEditorWarningOverlay" class="app-modal-overlay fixed inset-0 z-[92] hidden items-center justify-center px-4">
            <div class="app-modal-panel relative w-full overflow-hidden">
                <div class="p-6">
                    <span class="app-flag app-flag-critical">High Impact Area</span>
                    <p class="app-flag-tone-error mt-3 rounded-2xl border px-4 py-3 text-sm leading-6">
                        Any change in Website Editor affects the live website immediately.
                        Do not continue unless you are sure.
                    </p>
                    <div class="mt-6 app-modal-actions">
                        <button type="button"
                                id="closeWebsiteEditorWarning"
                                class="app-modal-btn app-modal-btn-muted">
                            Cancel
                        </button>
                        <a href="{{ route('admin.website-editor') }}"
                           class="app-modal-btn app-modal-btn-danger">
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
                    <div class="app-note-card {{ $isCritical ? 'is-critical' : ($isUnread ? 'is-unread' : '') }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-bold text-slate-900">{{ $data['title'] ?? 'Notification' }}</div>
                                <div class="text-sm opacity-80 mt-1">{{ $data['message'] ?? '' }}</div>
                                <div class="text-xs opacity-70 mt-2">{{ optional($notification->created_at)->format('d M Y, h:ia') }}</div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                @if($isCritical)
                                    <span class="app-flag app-flag-critical">Critical</span>
                                @endif
                                @if($isUnread)
                                    <span class="app-flag {{ $isCritical ? 'app-flag-critical' : 'app-flag-unread' }}">Unread</span>
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
                                <div class="amount-fit text-sm font-bold text-slate-900">N{{ number_format($o->amount / 100, 2) }}</div>
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
                                    <td class="amount-fit p-3 font-bold text-slate-900">N{{ number_format($o->amount / 100, 2) }}</td>
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
                                <div class="amount-fit text-sm font-bold text-slate-900">N{{ number_format($t->amount / 100, 2) }}</div>
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
                                    <td class="amount-fit p-3 font-bold text-slate-900">N{{ number_format($t->amount / 100, 2) }}</td>
                                    <td class="p-3">{{ strtoupper($t->status) }}</td>
                                    <td class="table-token p-3 text-xs text-slate-500">{{ $t->reference }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    @if($criticalAdminNotification)
        <div id="adminCriticalAlertOverlay" class="app-modal-overlay fixed inset-0 z-[110] hidden items-center justify-center px-4">
            <div class="app-modal-panel relative w-full overflow-hidden">
                <div class="p-6">
                    <span class="app-flag app-flag-critical">Critical Alert</span>
                    <div class="mt-3 text-2xl font-extrabold">{{ $criticalAdminNotification->data['title'] ?? 'Critical alert' }}</div>
                    <div class="app-flag-tone-error mt-3 rounded-2xl border px-4 py-4 text-sm leading-6">
                        {{ $criticalAdminNotification->data['message'] ?? '' }}
                    </div>
                    <div class="mt-6 app-modal-actions">
                        <button type="button" id="dismissAdminCriticalAlert" class="app-modal-btn app-modal-btn-muted">Close</button>
                        <a href="{{ route('admin.notifications.index') }}" class="app-modal-btn app-modal-btn-danger">View Notifications</a>
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
