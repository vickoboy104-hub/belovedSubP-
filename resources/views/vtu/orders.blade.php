<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">Transactions</h1>
            <p class="app-page-subtitle">The below table contains payment history of all transactions.</p>
            <div class="app-divider mt-4"></div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($orders as $o)
                    @php
                        $amountN = number_format(((int) $o->amount) / 100, 2);
                        $type = $o->meta['type'] ?? $o->service_id ?? 'order';
                        $status = $o->status ?? 'pending';
                        $balancePair = $orderBalanceMap[$o->id] ?? null;
                        $initialBalanceN = isset($balancePair['before_kobo']) && $balancePair['before_kobo'] !== null
                            ? number_format(((int) $balancePair['before_kobo']) / 100, 2)
                            : null;
                        $finalBalanceN = isset($balancePair['after_kobo']) && $balancePair['after_kobo'] !== null
                            ? number_format(((int) $balancePair['after_kobo']) / 100, 2)
                            : null;
                        $statusClasses = $status === 'success'
                            ? 'bg-emerald-50 text-emerald-700'
                            : ($status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700');
                    @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold capitalize text-slate-900">{{ str_replace('_', ' ', $type) }}</div>
                                <div class="mt-1 text-sm text-slate-500">ID: {{ $o->customer_ref }}</div>
                            </div>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $statusClasses }}">
                                {{ ucfirst($status) }}
                            </span>
                        </div>

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Amount</div>
                                <div class="app-record-value">&#8358;{{ $amountN }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Date</div>
                                <div class="app-record-value">{{ optional($o->created_at)->format('M j, Y, g:ia') }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Initial Balance</div>
                                <div class="app-record-value">{{ $initialBalanceN !== null ? '₦'.$initialBalanceN : 'N/A' }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Final Balance</div>
                                <div class="app-record-value">{{ $finalBalanceN !== null ? '₦'.$finalBalanceN : 'N/A' }}</div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <a href="{{ route('vtu.receipt', $o->id) }}" class="btn-primary w-full justify-center">View</a>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No orders yet.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-3 pr-4">Date</th>
                            <th class="py-3 pr-4">Service</th>
                            <th class="py-3 pr-4">Customer Ref</th>
                            <th class="py-3 pr-4">Amount</th>
                            <th class="py-3 pr-4">Initial Balance</th>
                            <th class="py-3 pr-4">Final Balance</th>
                            <th class="py-3 pr-4">Status</th>
                            <th class="py-3 pr-4">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-700">
                        @forelse($orders as $o)
                            @php
                                $amountN = number_format(((int) $o->amount) / 100, 2);
                                $type = $o->meta['type'] ?? $o->service_id ?? 'order';
                                $status = $o->status ?? 'pending';
                                $balancePair = $orderBalanceMap[$o->id] ?? null;
                                $initialBalanceN = isset($balancePair['before_kobo']) && $balancePair['before_kobo'] !== null
                                    ? number_format(((int) $balancePair['before_kobo']) / 100, 2)
                                    : null;
                                $finalBalanceN = isset($balancePair['after_kobo']) && $balancePair['after_kobo'] !== null
                                    ? number_format(((int) $balancePair['after_kobo']) / 100, 2)
                                    : null;
                            @endphp
                            <tr class="border-b border-slate-100">
                                <td class="py-3 pr-4">{{ optional($o->created_at)->format('d M, Y h:ia') }}</td>
                                <td class="py-3 pr-4 capitalize">{{ str_replace('_', ' ', $type) }}</td>
                                <td class="py-3 pr-4">{{ $o->customer_ref }}</td>
                                <td class="py-3 pr-4">N{{ $amountN }}</td>
                                <td class="py-3 pr-4">{{ $initialBalanceN !== null ? 'N'.$initialBalanceN : 'N/A' }}</td>
                                <td class="py-3 pr-4">{{ $finalBalanceN !== null ? 'N'.$finalBalanceN : 'N/A' }}</td>
                                <td class="py-3 pr-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold
                                        @if($status==='success') bg-emerald-50 text-emerald-700
                                        @elseif($status==='failed') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ strtoupper($status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4">
                                    <a href="{{ route('vtu.receipt', $o->id) }}" class="font-bold text-slate-900 hover:underline">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-slate-500">No orders yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">
                {{ $orders->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
