<x-app-layout>
    @php
        $statusTabs = [
            'pending' => 'Waiting',
            'success' => 'Completed',
            'failed' => 'Rejected',
            'all' => 'Everything',
        ];

        $tabLink = function (string $tab) use ($activeService) {
            return route('admin.manual-orders.index', array_filter([
                'status' => $tab,
                'service' => $activeService !== '' ? $activeService : null,
                'search' => request('search') ?: null,
            ]));
        };

        $readRequest = function ($o) use ($fulfilment) {
            $meta = is_array($o->meta) ? $o->meta : [];
            $expectedBy = null;

            if (!empty($meta['expected_by'])) {
                try {
                    $expectedBy = \Illuminate\Support\Carbon::parse((string) $meta['expected_by']);
                } catch (\Throwable $e) {
                    $expectedBy = null;
                }
            }

            return [
                'title' => (string) ($meta['manual_service_title'] ?? '—'),
                'slug' => (string) ($meta['manual_service'] ?? ''),
                'quick_look' => $fulfilment->quickLook(
                    (string) ($meta['manual_service'] ?? ''),
                    is_array($meta['submitted'] ?? null) ? $meta['submitted'] : [],
                ),
                'expected_by' => $expectedBy,
                'overdue' => $o->status === 'pending' && $expectedBy && $expectedBy->isPast(),
            ];
        };
    @endphp

    <x-page-hero class="reference-shared-banner"
                 title="Manual Requests"
                 subtitle="Identity services with no provider API. The customer has already paid — open a waiting request, do the job, then paste or upload the result.">
        <div class="flex flex-wrap gap-2">
            @foreach($statusTabs as $tab => $tabLabel)
                <a href="{{ $tabLink($tab) }}"
                   class="app-choice-chip {{ $activeStatus === $tab ? 'is-active' : '' }}">
                    {{ $tabLabel }} ({{ $counts[$tab] }})
                </a>
            @endforeach
        </div>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        @if($counts['pending'] > 0)
            <section class="app-note-card is-critical">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <span class="app-flag app-flag-critical">Action needed</span>
                        <p class="mt-2 text-sm leading-6">
                            {{ $counts['pending'] }} paid {{ $counts['pending'] === 1 ? 'request is' : 'requests are' }} waiting to be completed — worked oldest first.
                        </p>
                    </div>
                    <a href="{{ route('admin.manual-orders.index', ['status' => 'pending']) }}" class="btn-danger shrink-0">
                        Open the waiting queue
                    </a>
                </div>
            </section>
        @endif

        <section class="app-section p-4 sm:p-6">
            <form method="GET" class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <input type="hidden" name="status" value="{{ $activeStatus }}">

                <input type="text"
                       name="search"
                       value="{{ request('search') }}"
                       placeholder="Order id, reference, name, email or phone"
                       class="input-field" />

                <select name="service" class="input-field">
                    <option value="">All services</option>
                    @foreach($catalogue as $catalogueSlug => $catalogueService)
                        <option value="{{ $catalogueSlug }}" @selected($activeService === $catalogueSlug)>
                            {{ $catalogueService['title'] }}@unless(empty($serviceCounts[$catalogueSlug])) ({{ $serviceCounts[$catalogueSlug] }})@endunless
                        </option>
                    @endforeach
                </select>

                <button class="btn-primary justify-center">Apply filter</button>

                @if(request('search') || $activeService !== '')
                    <a href="{{ route('admin.manual-orders.index', ['status' => $activeStatus]) }}" class="btn-outline justify-center">
                        Clear filters
                    </a>
                @endif
            </form>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($orders as $o)
                    @php $row = $readRequest($o); @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">#{{ $o->id }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-700">{{ $row['title'] }}</div>
                            </div>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                                @if($o->status === 'success') bg-emerald-50 text-emerald-700
                                @elseif($o->status === 'failed') bg-rose-50 text-rose-700
                                @else bg-amber-50 text-amber-700
                                @endif">
                                {{ $o->status === 'pending' ? 'WAITING' : strtoupper($o->status) }}
                            </span>
                        </div>

                        <div class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                            <div class="text-sm font-bold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $o->user?->phone ?? '-' }}</div>
                        </div>

                        @if(count($row['quick_look']) > 0)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach($row['quick_look'] as $chip)
                                    <span class="app-choice-chip">{{ $chip['label'] }}: {{ $chip['value'] }}</span>
                                @endforeach
                            </div>
                        @endif

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Paid</div>
                                <div class="app-record-value">&#8358;{{ number_format($o->amount / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Submitted</div>
                                <div class="app-record-value">{{ $o->created_at->format('d M Y, h:i A') }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Promised by</div>
                                <div class="app-record-value {{ $row['overdue'] ? 'text-rose-600 font-bold' : '' }}">
                                    {{ $row['expected_by'] ? $row['expected_by']->format('d M Y, h:i A') : '—' }}
                                </div>
                            </div>
                        </div>

                        <a href="{{ route('admin.manual-orders.show', $o->id) }}" class="btn-primary mt-4 w-full justify-center">
                            {{ $o->status === 'pending' ? 'Process request' : 'Open' }}
                        </a>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                        {{ $activeStatus === 'pending' ? 'Nothing is waiting — every paid request has a result.' : 'No requests match this filter.' }}
                    </div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="p-4 text-left">ID</th>
                            <th class="p-4 text-left">Service</th>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Details collected</th>
                            <th class="p-4 text-left">Paid</th>
                            <th class="p-4 text-left">Submitted</th>
                            <th class="p-4 text-left">Promised by</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        @foreach($orders as $o)
                            @php $row = $readRequest($o); @endphp
                            <tr class="transition hover:bg-slate-50">
                                <td class="p-4 font-bold">#{{ $o->id }}</td>
                                <td class="p-4 font-semibold text-slate-900">{{ $row['title'] }}</td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-900">{{ $o->user?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500">{{ $o->user?->email ?? '-' }}</div>
                                    <div class="text-xs text-slate-500">{{ $o->user?->phone ?? '-' }}</div>
                                </td>
                                <td class="p-4">
                                    <div class="flex max-w-[22rem] flex-wrap gap-2">
                                        @forelse($row['quick_look'] as $chip)
                                            <span class="app-choice-chip">{{ $chip['label'] }}: {{ $chip['value'] }}</span>
                                        @empty
                                            <span class="text-xs text-slate-500">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="amount-fit p-4 font-bold text-slate-900">&#8358;{{ number_format($o->amount / 100, 2) }}</td>
                                <td class="p-4 whitespace-nowrap">{{ $o->created_at->format('d M Y, h:i A') }}</td>
                                <td class="p-4 whitespace-nowrap">
                                    @if($row['expected_by'])
                                        <span class="{{ $row['overdue'] ? 'font-bold text-rose-600' : 'text-slate-600' }}">
                                            {{ $row['expected_by']->format('d M Y, h:i A') }}
                                        </span>
                                        @if($row['overdue'])
                                            <div class="text-xs font-bold text-rose-600">Overdue</div>
                                        @endif
                                    @else
                                        —
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
