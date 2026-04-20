<x-app-layout>
    <div class="max-w-5xl space-y-5">

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 card-glow">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold">Wallet Transactions</h2>
                    <p class="text-white/60 text-sm mt-1">Credits, debits, funding requests, and refunds.</p>
                </div>
                <div class="text-right">
                    <div class="text-xs text-white/60">Balance</div>
                    <div class="text-xl font-extrabold">₦{{ number_format($walletBalanceNaira, 2) }}</div>
                </div>
            </div>

            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                <a href="{{ route('wallet.fund') }}"
                   class="px-5 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white font-extrabold transition text-center">
                    Fund Wallet
                </a>
                <a href="{{ route('vtu.orders') }}"
                   class="px-5 py-3 rounded-2xl bg-black/5 dark:bg-white/10 hover:bg-black/10 dark:hover:bg-white/15 border border-white/10 font-extrabold transition text-center">
                    View Orders
                </a>
            </div>

            @if(auth()->user()?->virtual_account_number)
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-2xl bg-black/10 dark:bg-black/30 border border-white/10 p-4">
                        <div class="text-xs text-white/60">Virtual Account Bank</div>
                        <div class="font-extrabold">{{ auth()->user()->virtual_account_bank ?: '-' }}</div>
                    </div>
                    <div class="rounded-2xl bg-black/10 dark:bg-black/30 border border-white/10 p-4">
                        <div class="text-xs text-white/60">Virtual Account Number</div>
                        <div class="font-extrabold tracking-wide">{{ auth()->user()->virtual_account_number }}</div>
                    </div>
                    <div class="rounded-2xl bg-black/10 dark:bg-black/30 border border-white/10 p-4">
                        <div class="text-xs text-white/60">Virtual Account Name</div>
                        <div class="font-extrabold">{{ auth()->user()->virtual_account_name ?: '-' }}</div>
                    </div>
                </div>
            @endif

            @if(session('success'))
                <div class="mt-4 p-4 rounded-2xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 text-green-700 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mt-4 p-4 rounded-2xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        <div class="rounded-3xl p-6 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-white/60">
                    <tr class="border-b border-white/10">
                        <th class="text-left py-3 pr-4">Date</th>
                        <th class="text-left py-3 pr-4">Type</th>
                        <th class="text-left py-3 pr-4">Amount</th>
                        <th class="text-left py-3 pr-4">Status</th>
                        <th class="text-left py-3 pr-4">Channel</th>
                        <th class="text-left py-3 pr-4">Reference</th>
                    </tr>
                </thead>
                <tbody class="text-white/80">
                    @forelse($transactions as $t)
                        @php
                            $amountN = number_format(((int)$t->amount)/100, 2);
                            $type = strtoupper($t->type ?? '-');
                            $status = $t->status ?? 'pending';
                            if ($status === 'pending' && ($t->type ?? '') === 'debit') {
                                $status = 'success';
                            }
                        @endphp
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4">{{ optional($t->created_at)->format('d M, Y h:ia') }}</td>
                            <td class="py-3 pr-4 font-bold">
                                @if(($t->type ?? '') === 'credit')
                                    <span class="text-green-200">CREDIT</span>
                                @else
                                    <span class="text-red-200">DEBIT</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                ₦{{ $amountN }}
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
                            <td class="py-3 pr-4">{{ $t->channel ?? '-' }}</td>
                            <td class="py-3 pr-4 text-white/70">{{ $t->reference ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-4 text-white/60">No wallet transactions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">
                {{ $transactions->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
