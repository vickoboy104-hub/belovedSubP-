<x-app-layout>
    <x-slot name="header">
        Admin Dashboard
    </x-slot>

    @if(session('success'))
        <div class="mb-4 p-4 rounded-2xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 text-green-700 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 rounded-2xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-4">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Total Users</p>
            <p class="text-lg sm:text-2xl font-extrabold mt-2">{{ $totalUsers }}</p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Total Orders</p>
            <p class="text-lg sm:text-2xl font-extrabold mt-2">{{ $totalOrders }}</p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Total Funding</p>
            <p class="text-lg sm:text-2xl font-extrabold text-orange-400 mt-2">
                N{{ number_format($totalFunding / 100, 2) }}
            </p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Total Purchases</p>
            <p class="text-lg sm:text-2xl font-extrabold text-orange-400 mt-2">
                N{{ number_format($totalPurchases / 100, 2) }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-2 sm:gap-4 mt-4">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Profit Today</p>
            <p class="text-sm sm:text-2xl font-extrabold text-green-300 mt-2">N{{ number_format($todayProfit / 100, 2) }}</p>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Profit This Month</p>
            <p class="text-sm sm:text-2xl font-extrabold text-green-300 mt-2">N{{ number_format($monthProfit / 100, 2) }}</p>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-5 card-glow">
            <p class="text-white/60 text-[11px] sm:text-sm">Profit This Year</p>
            <p class="text-sm sm:text-2xl font-extrabold text-green-300 mt-2">N{{ number_format($yearProfit / 100, 2) }}</p>
        </div>
    </div>

    <div class="mt-4 bg-white/5 border border-white/10 rounded-2xl p-3 sm:p-4">
        <p class="text-sm text-white/70 mb-3">Reset Totals</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <form method="POST" action="{{ route('admin.metrics.reset') }}">
                @csrf
                <input type="hidden" name="metric" value="funding">
                <button class="w-full px-2 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-[11px] sm:text-xs font-bold">Reset Funding Total</button>
            </form>
            <form method="POST" action="{{ route('admin.metrics.reset') }}">
                @csrf
                <input type="hidden" name="metric" value="purchases">
                <button class="w-full px-2 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-[11px] sm:text-xs font-bold">Reset Purchases Total</button>
            </form>
            <form method="POST" action="{{ route('admin.metrics.reset') }}">
                @csrf
                <input type="hidden" name="metric" value="profit">
                <button class="w-full px-2 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-[11px] sm:text-xs font-bold">Reset Profit Total</button>
            </form>
            <form method="POST" action="{{ route('admin.metrics.reset') }}">
                @csrf
                <input type="hidden" name="metric" value="all">
                <button class="w-full px-2 py-2 rounded-xl bg-red-700 hover:bg-red-800 text-white text-[11px] sm:text-xs font-extrabold">Reset All Totals</button>
            </form>
        </div>
    </div>

    <div class="mt-4 bg-white/5 border border-white/10 rounded-2xl p-4">
        <p class="text-sm text-white/70 mb-3">Admin Profit Calculator</p>
        <form method="GET" action="{{ route('admin.dashboard') }}" class="grid grid-cols-2 lg:grid-cols-5 gap-2">
            <select name="profit_period" class="px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
                <option value="today" @selected(($profitFilter['period'] ?? 'today') === 'today')>Today</option>
                <option value="month" @selected(($profitFilter['period'] ?? '') === 'month')>This Month</option>
                <option value="date" @selected(($profitFilter['period'] ?? '') === 'date')>Specific Date</option>
                <option value="range" @selected(($profitFilter['period'] ?? '') === 'range')>Date Range</option>
            </select>
            <input type="date" name="profit_date" value="{{ $profitFilter['date'] ?? '' }}" class="px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
            <input type="date" name="profit_from" value="{{ $profitFilter['from'] ?? '' }}" class="px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
            <input type="date" name="profit_to" value="{{ $profitFilter['to'] ?? '' }}" class="px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
            <button class="px-3 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 text-white text-sm font-bold">Calculate</button>
        </form>
        <div class="mt-3 text-sm text-white/70">
            Orders: <span class="font-bold">{{ (int) ($profitFilterResult['orders'] ?? 0) }}</span> |
            Profit: <span class="font-bold text-green-300">N{{ number_format(((int) ($profitFilterResult['profit'] ?? 0)) / 100, 2) }}</span>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-6">
        <a href="{{ route('admin.users') }}" class="px-2 sm:px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-xs sm:text-sm font-semibold text-center">
            Manage Users
        </a>

        <a href="{{ route('admin.orders') }}" class="px-2 sm:px-3 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 transition text-xs sm:text-sm font-semibold text-center">
            View Orders
        </a>

        <a href="{{ route('admin.wallet.transactions') }}" class="px-2 sm:px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-xs sm:text-sm font-semibold text-center">
            Wallet Transactions
        </a>

        <a href="{{ route('admin.support.chats') }}" class="px-2 sm:px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-xs sm:text-sm font-semibold text-center">
            Support Chats
        </a>

        <a href="{{ route('admin.settings') }}" class="px-2 sm:px-3 py-2 rounded-xl border border-white/10 hover:bg-white/10 transition text-xs sm:text-sm font-semibold text-center">
            Settings
        </a>

        <button type="button"
                id="openWebsiteEditorWarning"
                class="col-span-2 sm:col-span-4 px-2 sm:px-3 py-2 rounded-xl bg-red-600 hover:bg-red-700 transition text-xs sm:text-sm font-semibold text-white text-center">
            Website Editor
        </button>
    </div>

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
                            class="px-4 py-2 rounded-xl border border-white/15 hover:bg-white/10">
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

    <div class="mt-6 bg-white/5 border border-white/10 rounded-2xl p-5 sm:p-6 card-glow">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-extrabold text-lg">Admin Notifications</h3>
                <p class="text-sm text-white/60 mt-1">Unread: {{ $unreadAdminNotifications }}</p>
            </div>

            @if($unreadAdminNotifications > 0)
                <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                    @csrf
                    <button class="px-4 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 transition font-semibold text-sm">
                        Mark All as Read
                    </button>
                </form>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @forelse($adminNotifications as $notification)
                @php
                    $data = $notification->data ?? [];
                    $isUnread = is_null($notification->read_at);
                @endphp
                <div class="rounded-2xl border {{ $isUnread ? 'border-orange-500/30 bg-orange-500/10' : 'border-white/10 bg-black/10' }} p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-bold">{{ $data['title'] ?? 'Notification' }}</div>
                            <div class="text-sm text-white/70 mt-1">{{ $data['message'] ?? '' }}</div>
                            <div class="text-xs text-white/50 mt-2">
                                {{ optional($notification->created_at)->format('d M Y, h:ia') }}
                            </div>
                        </div>
                        @if($isUnread)
                            <span class="px-2 py-1 rounded-full text-xs font-bold bg-orange-500/20 text-orange-200">Unread</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-white/60 text-sm">No admin notifications yet.</div>
            @endforelse
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5 sm:p-6 card-glow">
            <h3 class="font-extrabold text-lg mb-4">Recent Orders</h3>

            <div class="overflow-x-auto rounded-xl border border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-white/5 text-white/70">
                        <tr>
                            <th class="p-3 text-left">ID</th>
                            <th class="p-3 text-left">Type</th>
                            <th class="p-3 text-left">Customer</th>
                            <th class="p-3 text-left">Amount</th>
                            <th class="p-3 text-left">Status</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-white/10">
                        @foreach($recentOrders as $o)
                            <tr class="hover:bg-white/5 transition">
                                <td class="p-3">#{{ $o->id }}</td>
                                <td class="p-3 font-semibold">{{ strtoupper($o->meta['type'] ?? '-') }}</td>
                                <td class="p-3 text-white/70">{{ $o->customer_ref }}</td>
                                <td class="p-3 font-bold text-orange-400">N{{ number_format($o->amount / 100, 2) }}</td>
                                <td class="p-3 text-white/70">{{ strtoupper($o->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <a href="{{ route('admin.orders') }}" class="inline-block mt-4 text-orange-400 font-semibold">
                View all orders ->
            </a>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-5 sm:p-6 card-glow">
            <h3 class="font-extrabold text-lg mb-4">Recent Wallet Transactions</h3>

            <div class="overflow-x-auto rounded-xl border border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-white/5 text-white/70">
                        <tr>
                            <th class="p-3 text-left">Type</th>
                            <th class="p-3 text-left">Amount</th>
                            <th class="p-3 text-left">Status</th>
                            <th class="p-3 text-left">Ref</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-white/10">
                        @foreach($recentTransactions as $t)
                            <tr class="hover:bg-white/5 transition">
                                <td class="p-3 font-semibold">{{ strtoupper($t->type) }}</td>
                                <td class="p-3 font-bold text-orange-400">N{{ number_format($t->amount / 100, 2) }}</td>
                                <td class="p-3 text-white/70">{{ strtoupper($t->status) }}</td>
                                <td class="p-3 text-xs text-white/70">{{ $t->reference }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const openBtn = document.getElementById('openWebsiteEditorWarning');
            const closeBtn = document.getElementById('closeWebsiteEditorWarning');
            const overlay = document.getElementById('websiteEditorWarningOverlay');
            if (!openBtn || !overlay) return;

            function openWarning() {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }

            function closeWarning() {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }

            openBtn.addEventListener('click', openWarning);
            closeBtn?.addEventListener('click', closeWarning);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeWarning();
            });
        })();
    </script>
</x-app-layout>
