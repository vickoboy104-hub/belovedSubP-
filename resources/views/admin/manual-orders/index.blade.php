<x-app-layout>
    <x-page-hero class="reference-shared-banner"
                 title="Manual Requests"
                 subtitle="Identity services with no provider API. The customer has already paid — complete or reject each request here." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        <section class="app-section grid gap-3 p-5 sm:grid-cols-3 sm:p-6">
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="app-record-label">Waiting for you</div>
                <div class="text-2xl font-extrabold text-amber-700">{{ $counts['waiting'] }}</div>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                <div class="app-record-label">Completed</div>
                <div class="text-2xl font-extrabold text-emerald-700">{{ $counts['completed'] }}</div>
            </div>
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <div class="app-record-label">Rejected &amp; refunded</div>
                <div class="text-2xl font-extrabold text-rose-700">{{ $counts['rejected'] }}</div>
            </div>
        </section>

        <section class="app-section p-6 sm:p-8">
            <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <input type="text"
                       name="search"
                       value="{{ request('search') }}"
                       placeholder="Search id / ref / customer..."
                       class="input-field" />

                <select name="service" class="input-field">
                    <option value="">All services</option>
                    @foreach($catalogue as $catalogueSlug => $catalogueService)
                        <option value="{{ $catalogueSlug }}" @selected(request('service') === $catalogueSlug)>
                            {{ $catalogueService['title'] }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="input-field">
                    <option value="">Waiting first</option>
                    <option value="pending" @selected(request('status') === 'pending')>Waiting only</option>
                    <option value="success" @selected(request('status') === 'success')>Completed</option>
                    <option value="failed" @selected(request('status') === 'failed')>Rejected</option>
                </select>

                <button class="btn-primary justify-center">Apply</button>
            </form>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($orders as $o)
                    @php
                        $meta = is_array($o->meta) ? $o->meta : [];
                        $statusClasses = $o->status === 'success'
                            ? 'bg-emerald-50 text-emerald-700'
                            : ($o->status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');
                    @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">#{{ $o->id }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-700">{{ $meta['manual_service_title'] ?? '—' }}</div>
                            </div>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses }}">
                                {{ $o->status === 'pending' ? 'WAITING' : strtoupper($o->status) }}
                            </span>
                        </div>

                        <div class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                            <div class="text-sm font-bold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                        </div>

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Paid</div>
                                <div class="app-record-value">&#8358;{{ number_format($o->amount / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Submitted</div>
                                <div class="app-record-value">{{ $o->created_at->format('d M Y, h:i A') }}</div>
                            </div>
                        </div>

                        <a href="{{ route('admin.manual-orders.show', $o->id) }}" class="btn-primary mt-4 w-full justify-center">
                            {{ $o->status === 'pending' ? 'Process request' : 'Open' }}
                        </a>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No manual requests yet.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="p-4 text-left">ID</th>
                            <th class="p-4 text-left">Service</th>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Reference</th>
                            <th class="p-4 text-left">Paid</th>
                            <th class="p-4 text-left">Submitted</th>
                            <th class="p-4 text-left">Promised by</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        @foreach($orders as $o)
                            @php
                                $meta = is_array($o->meta) ? $o->meta : [];
                                $expectedBy = null;
                                if (!empty($meta['expected_by'])) {
                                    try {
                                        $expectedBy = \Illuminate\Support\Carbon::parse((string) $meta['expected_by']);
                                    } catch (\Throwable $e) {
                                        $expectedBy = null;
                                    }
                                }
                                $overdue = $o->status === 'pending' && $expectedBy && $expectedBy->isPast();
                            @endphp
                            <tr class="transition hover:bg-slate-50">
                                <td class="p-4 font-bold">#{{ $o->id }}</td>
                                <td class="p-4 font-semibold text-slate-900">{{ $meta['manual_service_title'] ?? '—' }}</td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                                </td>
                                <td class="p-4 text-xs text-slate-500">{{ $o->customer_ref }}</td>
                                <td class="p-4 font-bold text-slate-900">&#8358;{{ number_format($o->amount / 100, 2) }}</td>
                                <td class="p-4">{{ $o->created_at->format('d M Y, h:i A') }}</td>
                                <td class="p-4">
                                    @if($expectedBy)
                                        <span class="{{ $overdue ? 'font-bold text-rose-600' : 'text-slate-600' }}">
                                            {{ $expectedBy->format('d M Y, h:i A') }}
                                        </span>
                                        @if($overdue)<div class="text-xs font-bold text-rose-600">Overdue</div>@endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="p-4">
                                    <span class="rounded-xl px-3 py-1 text-xs font-bold
                                        @if($o->status === 'success') bg-emerald-50 text-emerald-700
                                        @elseif($o->status === 'failed') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ $o->status === 'pending' ? 'WAITING' : strtoupper($o->status) }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <a href="{{ route('admin.manual-orders.show', $o->id) }}" class="btn-outline whitespace-nowrap">
                                        {{ $o->status === 'pending' ? 'Process' : 'Open' }}
                                    </a>
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
