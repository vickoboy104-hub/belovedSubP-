<x-app-layout>
    <x-slot name="header">
        All Orders
    </x-slot>

    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 sm:p-10 card-glow">

        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-extrabold">Orders</h2>
                <p class="text-white/60 mt-1">View, search and monitor all transactions.</p>
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Search id/ref/user..."
                class="w-full rounded-2xl bg-black/30 border border-white/10 text-white placeholder:text-white/40 px-4 py-3 focus:ring-2 focus:ring-orange-500 outline-none"
            />

            <select name="type" class="w-full rounded-2xl bg-black/30 border border-white/10 text-white px-4 py-3">
                <option value="">All Types</option>
                <option value="airtime" @selected(request('type')=='airtime')>Airtime</option>
                <option value="data" @selected(request('type')=='data')>Data</option>
                <option value="cable" @selected(request('type')=='cable')>Cable</option>
                <option value="electricity" @selected(request('type')=='electricity')>Electricity</option>
                <option value="exam" @selected(request('type')=='exam')>Exam</option>
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
                        <th class="p-4 text-left">ID</th>
                        <th class="p-4 text-left">User</th>
                        <th class="p-4 text-left">Type</th>
                        <th class="p-4 text-left">Customer</th>
                        <th class="p-4 text-left">Details</th>
                        <th class="p-4 text-left">Amount</th>
                        <th class="p-4 text-left">Status</th>
                        <th class="p-4 text-left">Provider Ref</th>
                        <th class="p-4 text-left">Date</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-white/10">
                    @foreach($orders as $o)
                        @php
                            $meta = $o->meta ?? [];
                            $details = [];
                            if (!empty($meta['phone'])) $details[] = 'Phone: ' . $meta['phone'];
                            if (!empty($meta['customerID'])) $details[] = 'Customer ID: ' . $meta['customerID'];
                            if (!empty($meta['plan'])) $details[] = 'Plan: ' . $meta['plan'];
                            if (!empty($meta['meter_type'])) $details[] = 'Meter: ' . strtoupper($meta['meter_type']);
                            if (!empty($meta['pin_code'])) $details[] = 'Pin: ' . strtoupper($meta['pin_code']);
                            if (!empty($meta['discount_percent']) && (float) $meta['discount_percent'] > 0) {
                                $details[] = 'Discount: ' . $meta['discount_percent'] . '%';
                            }
                            $detailsText = !empty($details) ? implode(' | ', $details) : '-';
                        @endphp
                        <tr class="hover:bg-white/5 transition">
                            <td class="p-4 font-bold">#{{ $o->id }}</td>
                            <td class="p-4 text-white/70">
                                <div class="font-semibold text-white/90">{{ $o->user?->name ?? 'Unknown' }}</div>
                                <div class="text-xs text-white/60">{{ $o->user?->email ?? '-' }}</div>
                            </td>
                            <td class="p-4 text-white/70">{{ strtoupper($o->meta['type'] ?? '-') }}</td>
                            <td class="p-4 text-white/70">{{ $o->customer_ref }}</td>
                            <td class="p-4 text-xs text-white/60">{{ $detailsText }}</td>
                            <td class="p-4 font-bold text-orange-300">₦{{ number_format($o->amount / 100, 2) }}</td>
                            <td class="p-4">
                                <span class="px-3 py-1 rounded-xl text-xs font-bold
                                    @if($o->status === 'success') bg-green-500/10 text-green-300 border border-green-500/20
                                    @elseif($o->status === 'failed') bg-red-500/10 text-red-300 border border-red-500/20
                                    @else bg-yellow-500/10 text-yellow-300 border border-yellow-500/20
                                    @endif">
                                    {{ strtoupper($o->status) }}
                                </span>
                            </td>
                            <td class="p-4 text-xs text-white/60">{{ $o->provider_reference ?? '-' }}</td>
                            <td class="p-4 text-white/70">{{ $o->created_at->format('d M Y, h:i A') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>

    </div>
</x-app-layout>
