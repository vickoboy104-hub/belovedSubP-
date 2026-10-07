<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Wallet Transactions" subtitle="Credits, debits, funding requests and refunds." />

    @php
        $badge = fn (string $status): array => match ($status) {
            'success' => ['value' => 'Success', 'tone' => 'success'],
            'failed' => ['value' => 'Failed', 'tone' => 'danger'],
            default => ['value' => 'Pending', 'tone' => 'warning'],
        };

        $columns = [
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'channel', 'label' => 'Channel'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'date', 'label' => 'Date'],
        ];

        $rows = [];
        foreach ($transactions as $t) {
            $status = (string) ($t->status ?? 'pending');
            $isCredit = ($t->type ?? '') === 'credit';

            // A debit is applied to the wallet the moment it is written, so a
            // debit still marked pending is a closed job, not an open one.
            if ($status === 'pending' && !$isCredit) {
                $status = 'success';
            }

            $description = trim((string) ($t->description ?? ''));
            if ($description === '') {
                $description = ucfirst(($t->channel ?? 'wallet')).' '.($isCredit ? 'credit' : 'debit');
            }

            $rows[] = [
                'id' => (int) $t->id,
                'cells' => [
                    'description' => $description,
                    'type' => strtoupper($isCredit ? 'credit' : 'debit'),
                    'amount' => ($isCredit ? '+' : '−').'₦'.number_format(((int) $t->amount) / 100, 2),
                    'status' => $badge($status),
                    'channel' => (string) ($t->channel ?? ''),
                    'reference' => (string) ($t->reference ?? ''),
                    'date' => optional($t->created_at)->format('Y-m-d, h:i:s A') ?? '',
                ],
            ];
        }
    @endphp

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
            <x-records-table
                :columns="$columns"
                :rows="$rows"
                :compact="['description', 'amount', 'status', 'date']"
                :total="$totalRecords"
                :page="$transactions->currentPage()"
                :pages="$transactions->lastPage()"
                :per-page="$perPage"
                :search="$search"
                search-placeholder="Search by reference or description"
                empty-text="No wallet transactions yet."
            />

            <div class="mt-5">
                {{ $transactions->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
