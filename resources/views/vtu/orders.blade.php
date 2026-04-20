<x-app-layout>
    <div class="max-w-5xl space-y-5">
        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-extrabold">My Orders</h2>
                    <p class="text-white/60 text-sm mt-1">Your recent transactions and their statuses.</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-black/5 dark:bg-white/10 border border-white/10 flex items-center justify-center text-2xl">
                    &#128220;
                </div>
            </div>
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-white/60">
                    <tr class="border-b border-white/10">
                        <th class="text-left py-3 pr-4">Date</th>
                        <th class="text-left py-3 pr-4">Service</th>
                        <th class="text-left py-3 pr-4">Customer Ref</th>
                        <th class="text-left py-3 pr-4">Amount</th>
                        <th class="text-left py-3 pr-4">Initial Balance</th>
                        <th class="text-left py-3 pr-4">Final Balance</th>
                        <th class="text-left py-3 pr-4">Status</th>
                        <th class="text-left py-3 pr-4">Receipt</th>
                    </tr>
                </thead>
                <tbody class="text-white/80">
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
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">{{ optional($o->created_at)->format('d M, Y h:ia') }}</td>
                            <td class="py-3 pr-4 capitalize">{{ str_replace('_', ' ', $type) }}</td>
                            <td class="py-3 pr-4">{{ $o->customer_ref }}</td>
                            <td class="py-3 pr-4">N{{ $amountN }}</td>
                            <td class="py-3 pr-4">
                                @if($initialBalanceN !== null)
                                    N{{ $initialBalanceN }}
                                @else
                                    N/A
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if($finalBalanceN !== null)
                                    N{{ $finalBalanceN }}
                                @else
                                    N/A
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <span class="px-3 py-1 rounded-full text-xs font-bold
                                    @if($status==='success') bg-green-500/15 text-green-200 border border-green-500/20
                                    @elseif($status==='failed') bg-red-500/15 text-red-200 border border-red-500/20
                                    @else bg-yellow-500/15 text-yellow-200 border border-yellow-500/20
                                    @endif">
                                    {{ strtoupper($status) }}
                                </span>
                            </td>
                            <td class="py-3 pr-4">
                                <a href="{{ route('vtu.receipt', $o->id) }}"
                                   class="text-orange-300 hover:text-orange-200 font-bold">
                                    View ->
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-4 text-white/60">
                                No orders yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">
                {{ $orders->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
