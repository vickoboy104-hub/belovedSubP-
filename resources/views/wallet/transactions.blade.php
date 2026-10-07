<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Wallet Transactions" subtitle="Credits, debits, funding requests and refunds." />

    <div class="reference-flow-page mx-auto max-w-5xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <div class="rounded-[22px] w-fit sm:ml-auto border border-slate-200 bg-slate-50 px-5 py-4 text-left sm:text-right">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Balance</div>
                <div class="app-ledger-amount mt-2 text-2xl font-extrabold text-slate-900" id="walletBalance" data-wallet-kobo="{{ (int) ($walletBalanceKobo ?? 0) }}">&#8358;{{ number_format($walletBalanceNaira, 2) }}</div>
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('wallet.fund') }}" class="btn-primary text-center">Fund Wallet</a>
                <a href="{{ route('vtu.orders') }}" class="btn-outline text-center">View Orders</a>
            </div>

            @if(auth()->user()?->virtual_account_number)
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold text-slate-500">Virtual Account Bank</div>
                        <div class="mt-2 text-sm font-extrabold text-slate-900">{{ auth()->user()->virtual_account_bank ?: '-' }}</div>
                    </div>
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold text-slate-500">Virtual Account Number</div>
                        <div class="mt-2 text-sm font-extrabold tracking-wide text-slate-900">{{ auth()->user()->virtual_account_number }}</div>
                    </div>
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold text-slate-500">Virtual Account Name</div>
                        <div class="mt-2 text-sm font-extrabold text-slate-900">{{ auth()->user()->virtual_account_name ?: '-' }}</div>
                    </div>
                </div>
            @endif

        </section>

        {{-- The transfers still in flight are already rows in the table below, so
             this panel only carries the check button and what the last check said. --}}
        <x-deposit-status :check="$depositCheck" />

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-3 md:hidden">
                @forelse($transactions as $t)
                    @php
                        $amountN = number_format(((int)$t->amount)/100, 2);
                        $status = $t->status ?? 'pending';
                        if ($status === 'pending' && ($t->type ?? '') === 'debit') {
                            $status = 'success';
                        }
                        $isCredit = ($t->type ?? '') === 'credit';
                        $statusClass = match ($status) {
                            'success' => 'app-ledger-success',
                            'failed' => 'app-ledger-failed',
                            default => 'app-ledger-pending',
                        };
                    @endphp
                    <article class="app-record-card">
                        <div class="app-ledger-head flex justify-between">
                            <div>
                                <div class="text-sm font-extrabold {{ $isCredit ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ $isCredit ? 'CREDIT' : 'DEBIT' }}
                                </div>
                                <div class="mt-0.5 text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    {{ $t->channel ?? 'wallet' }}
                                </div>
                            </div>
                            <div class="app-ledger-amount text-lg font-extrabold text-slate-900">&#8358;{{ $amountN }}</div>
                        </div>

                        <div class="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                            <div class="text-xs font-semibold text-slate-500">{{ optional($t->created_at)->format('d M Y, g:ia') }}</div>
                            <span class="app-ledger-status {{ $statusClass }}">{{ $status }}</span>
                        </div>

                        @if($status !== 'success' && !empty($t->description))
                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ $t->description }}</p>
                        @endif

                        <div class="app-ledger-ref mt-2">{{ $t->reference ?? '-' }}</div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No wallet transactions yet.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-3 pr-4">Date</th>
                            <th class="py-3 pr-4">Type</th>
                            <th class="py-3 pr-4">Amount</th>
                            <th class="py-3 pr-4">Status</th>
                            <th class="py-3 pr-4">Channel</th>
                            <th class="py-3 pr-4">Reference</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-700">
                        @forelse($transactions as $t)
                            @php
                                $amountN = number_format(((int)$t->amount)/100, 2);
                                $status = $t->status ?? 'pending';
                                if ($status === 'pending' && ($t->type ?? '') === 'debit') {
                                    $status = 'success';
                                }
                            @endphp
                            <tr class="border-b border-slate-100">
                                <td class="py-3 pr-4">{{ optional($t->created_at)->format('d M, Y h:ia') }}</td>
                                <td class="py-3 pr-4 font-bold">
                                    @if(($t->type ?? '') === 'credit')
                                        <span class="text-emerald-700">CREDIT</span>
                                    @else
                                        <span class="text-rose-700">DEBIT</span>
                                    @endif
                                </td>
                                <td class="app-ledger-amount py-3 pr-4">&#8358;{{ $amountN }}</td>
                                <td class="py-3 pr-4">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold
                                        @if($status==='success') bg-emerald-50 text-emerald-700
                                        @elseif($status==='failed') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700
                                        @endif">
                                        {{ strtoupper($status) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4">{{ $t->channel ?? '-' }}</td>
                                <td class="table-token py-3 pr-4 text-slate-500">{{ $t->reference ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-4 text-slate-500">No wallet transactions yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">
                {{ $transactions->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
