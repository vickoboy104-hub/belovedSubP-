<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">Wallet Transactions</h1>
            <p class="app-page-subtitle">All credits, debits, funding requests, and refunds.</p>
            <div class="app-divider mt-4"></div>

            <form method="GET" class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-4">
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Search user/ref/channel..."
                    class="input-field"
                />

                <select name="type" class="input-field">
                    <option value="">All Types</option>
                    <option value="credit" @selected(request('type')=='credit')>Credit</option>
                    <option value="debit" @selected(request('type')=='debit')>Debit</option>
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
                @forelse($transactions as $t)
                    @php
                        $status = $t->status ?? 'pending';
                        $statusClasses = $status === 'success'
                            ? 'bg-emerald-50 text-emerald-700'
                            : ($status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');
                    @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">{{ strtoupper($t->type ?? '-') }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ optional($t->created_at)->format('d M Y, h:i A') }}</div>
                            </div>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses }}">
                                {{ strtoupper($status) }}
                            </span>
                        </div>

                        <div class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                            <div class="text-sm font-bold text-slate-900">{{ $t->wallet?->user?->name ?? 'Unknown' }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $t->wallet?->user?->email ?? '-' }}</div>
                        </div>

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Amount</div>
                                <div class="app-record-value amount-fit">&#8358;{{ number_format(($t->amount ?? 0) / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Channel</div>
                                <div class="app-record-value">{{ $t->channel ?? '-' }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="app-record-label">Description</div>
                                <div class="app-record-value">{{ $t->description ?? '-' }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="app-record-label">Reference</div>
                                <div class="app-record-value break-all">{{ $t->reference ?? '-' }}</div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No wallet transactions found.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
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

                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        @foreach($transactions as $t)
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-4">{{ optional($t->created_at)->format('d M Y, h:i A') }}</td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-900">{{ $t->wallet?->user?->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500">{{ $t->wallet?->user?->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">{{ strtoupper($t->type ?? '-') }}</td>
                                <td class="p-4 font-bold text-slate-900 amount-fit">&#8358;{{ number_format(($t->amount ?? 0) / 100, 2) }}</td>
                                <td class="p-4">
                                    <span class="px-3 py-1 rounded-xl text-xs font-bold
                                        @if($t->status === 'success') bg-emerald-50 text-emerald-700
                                        @elseif($t->status === 'failed') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ strtoupper($t->status ?? 'pending') }}
                                    </span>
                                </td>
                                <td class="p-4">{{ $t->channel ?? '-' }}</td>
                                <td class="p-4 text-xs text-slate-500">{{ $t->description ?? '-' }}</td>
                                <td class="p-4 text-xs text-slate-500 table-token">{{ $t->reference ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                {{ $transactions->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
