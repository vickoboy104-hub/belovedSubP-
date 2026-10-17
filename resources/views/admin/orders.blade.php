<x-app-layout>
    @php
        $mask = '\App\Support\SensitiveFigures::mask';

        // One build of the details line, used by the phone cards and the desktop
        // table alike: two copies of a masking rule is how one of them ends up
        // printing what the other hides.
        $orderRow = function ($o) use ($mask): array {
            $meta = is_array($o->meta ?? null) ? $o->meta : [];
            $details = [];

            // A phone number, a CA/customer ID and a meter number each single out a
            // person or a premises, and this board prints many of them at once, so
            // they keep their last digits only. The pin is the thing the customer
            // paid to receive, so it stays whole for whoever has to re-send it.
            if (!empty($meta['phone'])) $details[] = 'Phone: '.$mask((string) $meta['phone']);
            if (!empty($meta['customerID'])) $details[] = 'Customer ID: '.$mask((string) $meta['customerID']);
            if (!empty($meta['plan'])) $details[] = 'Plan: '.$meta['plan'];
            if (!empty($meta['meter_type'])) $details[] = 'Meter: '.strtoupper((string) $meta['meter_type']);
            if (!empty($meta['pin_code'])) $details[] = 'Pin: '.strtoupper((string) $meta['pin_code']);
            if (!empty($meta['discount_percent']) && (float) $meta['discount_percent'] > 0) {
                $details[] = 'Discount: '.$meta['discount_percent'].'%';
            }

            return [
                'meta' => $meta,
                'details' => $details !== [] ? implode(' | ', $details) : '-',
            ];
        };
    @endphp
    <x-page-hero class="reference-shared-banner" title="All Orders" subtitle="View, search and monitor every transaction." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-4">
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

            <p class="mt-4 text-xs text-slate-500">
                Phone, customer ID and meter numbers are masked on this board; the service type, plan and the pin the
                customer paid for stay readable. Reading a row here never moves money or re-sends a purchase.
            </p>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($orders as $o)
                    @php
                        $row = $orderRow($o);
                        $meta = $row['meta'];
                        $detailsText = $row['details'];
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
                                <div class="app-record-value">{{ $o->customer_ref ? $mask($o->customer_ref) : '-' }}</div>
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
                                @if($o->provider_reference)
                                    <button type="button" class="records-copy"
                                            data-copy-text="{{ $o->provider_reference }}"
                                            aria-label="Copy provider reference">Copy</button>
                                @endif
                            </div>
                            <div>
                                <div class="app-record-label">Date</div>
                                <div class="app-record-value">{{ $o->created_at->format('d M Y, h:i A') }}</div>
                            </div>
                        </div>

                        @if(in_array((string) ($meta['type'] ?? ''), ['exam', 'electricity'], true))
                            <a href="{{ route('admin.orders.keys', $o->id) }}" class="btn-outline mt-3 w-full justify-center">
                                {{ \App\Support\IssuedKeys::forOrder($o) !== [] ? 'View issued keys' : 'Issue keys' }}
                            </a>
                        @endif
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
                            <th class="p-4 text-left">Keys</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        @foreach($orders as $o)
                            @php
                                $row = $orderRow($o);
                                $meta = $row['meta'];
                                $detailsText = $row['details'];
                            @endphp
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-4 font-bold">#{{ $o->id }}</td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">{{ strtoupper($o->meta['type'] ?? '-') }}</td>
                                <td class="p-4">{{ $o->customer_ref ? $mask($o->customer_ref) : '-' }}</td>
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
                                <td class="p-4 text-xs text-slate-500">
                                    {{ $o->provider_reference ?? '-' }}
                                    @if($o->provider_reference)
                                        <button type="button" class="records-copy"
                                                data-copy-text="{{ $o->provider_reference }}"
                                                aria-label="Copy provider reference">Copy</button>
                                    @endif
                                </td>
                                <td class="p-4">{{ $o->created_at->format('d M Y, h:i A') }}</td>
                                <td class="p-4">
                                    @if(in_array((string) ($meta['type'] ?? ''), ['exam', 'electricity'], true))
                                        <a href="{{ route('admin.orders.keys', $o->id) }}"
                                           class="text-xs font-bold text-emerald-700 hover:text-emerald-800">
                                            {{ \App\Support\IssuedKeys::forOrder($o) !== [] ? 'Issued' : 'Issue keys' }}
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </td>
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
