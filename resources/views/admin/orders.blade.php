<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">All Orders</h1>
            <p class="app-page-subtitle">View, search and monitor all transactions.</p>
            <div class="app-divider mt-4"></div>

            <form method="GET" class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-4">
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Search id/ref/user..."
                    class="input-field"
                />

                <select name="type" class="input-field">
                    <option value="">All Types</option>
                    <option value="airtime" @selected(request('type')=='airtime')>Airtime</option>
                    <option value="data" @selected(request('type')=='data')>Data</option>
                    <option value="cable" @selected(request('type')=='cable')>Cable</option>
                    <option value="electricity" @selected(request('type')=='electricity')>Electricity</option>
                    <option value="exam" @selected(request('type')=='exam')>Exam</option>
                </select>

                <select name="status" class="input-field">
                    <option value="">All Status</option>
                    <option value="success" @selected(request('status')=='success')>Success</option>
                    <option value="pending" @selected(request('status')=='pending')>Pending</option>
                    <option value="failed" @selected(request('status')=='failed')>Failed</option>
                </select>

                <button class="btn-primary justify-center">Apply</button>
            </form>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($orders as $o)
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
                        $statusClasses = $o->status === 'success'
                            ? 'bg-emerald-50 text-emerald-700'
                            : ($o->status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');
                    @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">#{{ $o->id }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-700">{{ strtoupper($o->meta['type'] ?? '-') }}</div>
                            </div>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses }}">
                                {{ strtoupper($o->status) }}
                            </span>
                        </div>

                        <div class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                            <div class="text-sm font-bold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                        </div>

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Customer</div>
                                <div class="app-record-value">{{ $o->customer_ref }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Amount</div>
                                <div class="app-record-value">&#8358;{{ number_format($o->amount / 100, 2) }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="app-record-label">Details</div>
                                <div class="app-record-value text-sm leading-6">{{ $detailsText }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Provider Ref</div>
                                <div class="app-record-value break-all">{{ $o->provider_reference ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Date</div>
                                <div class="app-record-value">{{ $o->created_at->format('d M Y, h:i A') }}</div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No orders found.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
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

                    <tbody class="divide-y divide-slate-200 text-slate-700">
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
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-4 font-bold">#{{ $o->id }}</td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">{{ strtoupper($o->meta['type'] ?? '-') }}</td>
                                <td class="p-4">{{ $o->customer_ref }}</td>
                                <td class="p-4 text-xs text-slate-500">{{ $detailsText }}</td>
                                <td class="p-4 font-bold text-slate-900">&#8358;{{ number_format($o->amount / 100, 2) }}</td>
                                <td class="p-4">
                                    <span class="px-3 py-1 rounded-xl text-xs font-bold
                                        @if($o->status === 'success') bg-emerald-50 text-emerald-700
                                        @elseif($o->status === 'failed') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ strtoupper($o->status) }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-slate-500">{{ $o->provider_reference ?? '-' }}</td>
                                <td class="p-4">{{ $o->created_at->format('d M Y, h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                {{ $orders->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
