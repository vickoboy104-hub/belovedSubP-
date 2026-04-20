<x-app-layout>
    <x-slot name="header">
        Wallet Transactions
    </x-slot>

    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 sm:p-10 card-glow">

        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-extrabold">Wallet Transactions</h2>
                <p class="text-white/60 mt-1">All credits, debits, funding requests, and refunds.</p>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Search user/ref/channel..."
                class="w-full rounded-2xl bg-black/30 border border-white/10 text-white placeholder:text-white/40 px-4 py-3 focus:ring-2 focus:ring-orange-500 outline-none"
            />

            <select name="type" class="w-full rounded-2xl bg-black/30 border border-white/10 text-white px-4 py-3">
                <option value="">All Types</option>
                <option value="credit" @selected(request('type')=='credit')>Credit</option>
                <option value="debit" @selected(request('type')=='debit')>Debit</option>
            </select>

            <select name="status" class="w-full rounded-2xl bg-black/30 border border-white/10 text-white px-4 py-3">
                <option value="">All Status</option>
                <option value="success" @selected(request('status')=='success')>Success</option>
                <option value="pending" @selected(request('status')=='pending')>Pending</option>
                <option value="failed" @selected(request('status')=='failed')>Failed</option>
            </select>

            <button class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 font-bold text-white">
                Apply
            </button>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-white/10">
            <table class="min-w-full text-sm">
                <thead class="bg-black/30 text-white/70">
                    <tr>
                        <th class="p-4 text-left">Date</th>
                        <th class="p-4 text-left">User</th>
                        <th class="p-4 text-left">Type</th>
                        <th class="p-4 text-left">Amount</th>
                        <th class="p-4 text-left">Status</th>
                        <th class="p-4 text-left">Channel</th>
                        <th class="p-4 text-left">Description</th>
                        <th class="p-4 text-left">Reference</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-white/10">
                    @foreach($transactions as $t)
                        <tr class="hover:bg-white/5 transition">
                            <td class="p-4 text-white/70">{{ optional($t->created_at)->format('d M Y, h:i A') }}</td>
                            <td class="p-4 text-white/70">
                                <div class="font-semibold text-white/90">{{ $t->wallet?->user?->name ?? 'Unknown' }}</div>
                                <div class="text-xs text-white/60">{{ $t->wallet?->user?->email ?? '-' }}</div>
                            </td>
                            <td class="p-4 text-white/70">{{ strtoupper($t->type ?? '-') }}</td>
                            <td class="p-4 font-bold text-orange-300">â‚¦{{ number_format(($t->amount ?? 0) / 100, 2) }}</td>
                            <td class="p-4">
                                <span class="px-3 py-1 rounded-xl text-xs font-bold
                                    @if($t->status === 'success') bg-green-500/10 text-green-300 border border-green-500/20
                                    @elseif($t->status === 'failed') bg-red-500/10 text-red-300 border border-red-500/20
                                    @else bg-yellow-500/10 text-yellow-300 border border-yellow-500/20
                                    @endif">
                                    {{ strtoupper($t->status ?? 'pending') }}
                                </span>
                            </td>
                            <td class="p-4 text-white/70">{{ $t->channel ?? '-' }}</td>
                            <td class="p-4 text-xs text-white/60">{{ $t->description ?? '-' }}</td>
                            <td class="p-4 text-xs text-white/60">{{ $t->reference ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $transactions->links() }}
        </div>
    </div>
</x-app-layout>
