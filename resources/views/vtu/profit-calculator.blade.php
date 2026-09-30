<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Profit Calculator" subtitle="Check your totals by day, month, or custom date range." />

    <div class="reference-flow-page max-w-4xl mx-auto w-full px-4 sm:px-0 space-y-5">
        <form method="GET" action="{{ route('vtu.profit-calculator') }}"
              class="rounded-3xl p-5 sm:p-6 border border-gray-200 bg-white space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="text-sm font-bold text-gray-700">Period</label>
                    <select name="period"
                            class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                        <option value="today" @selected(($filters['period'] ?? '') === 'today')>Today</option>
                        <option value="month" @selected(($filters['period'] ?? '') === 'month')>This Month</option>
                        <option value="date" @selected(($filters['period'] ?? '') === 'date')>Specific Date</option>
                        <option value="range" @selected(($filters['period'] ?? '') === 'range')>Custom Range</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-bold text-gray-700">Date</label>
                    <input type="date" name="date" value="{{ $filters['date'] ?? '' }}"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                </div>
                <div>
                    <label class="text-sm font-bold text-gray-700">From</label>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                </div>
                <div>
                    <label class="text-sm font-bold text-gray-700">To</label>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                           class="w-full mt-1 px-4 py-3 rounded-2xl bg-white border border-gray-300 text-gray-900">
                </div>
            </div>

            <button class="px-6 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition">
                Calculate
            </button>
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl p-4 border border-gray-200 bg-white">
                <div class="text-xs text-gray-500">Successful Orders</div>
                <div class="text-2xl font-extrabold mt-1">{{ number_format($summary['orders']) }}</div>
            </div>
            <div class="rounded-2xl p-4 border border-gray-200 bg-white">
                <div class="text-xs text-gray-500">Total Spent</div>
                <div class="text-2xl font-extrabold mt-1">N{{ number_format($summary['spent'] / 100, 2) }}</div>
            </div>
            <div class="rounded-2xl p-4 border border-gray-200 bg-white">
                <div class="text-xs text-gray-500">Estimated Profit/Savings</div>
                <div class="text-2xl font-extrabold mt-1">N{{ number_format($summary['profit'] / 100, 2) }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
