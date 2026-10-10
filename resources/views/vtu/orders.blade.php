<x-app-layout>
    <x-page-hero class="reference-shared-banner" title="Transactions" subtitle="Every purchase you made, newest first." />

    @php
        // The provider's ledger wording, kept honest: an order whose balance could
        // not be reconstructed shows no figure rather than a made-up one.
        $money = fn (?int $kobo): string => $kobo === null ? '' : '₦'.number_format($kobo / 100, 2);
        $badge = fn (string $status): array => match ($status) {
            'success' => ['value' => 'Success', 'tone' => 'success'],
            'failed' => ['value' => 'Failed', 'tone' => 'danger'],
            default => ['value' => 'Pending', 'tone' => 'warning'],
        };

        $columns = [
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'reference', 'label' => 'Customer Ref'],
            ['key' => 'amount', 'label' => 'Amount'],
            ['key' => 'before', 'label' => 'Balance Before'],
            ['key' => 'after', 'label' => 'Balance After'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'date', 'label' => 'Date'],
        ];

        $rows = [];
        foreach ($orders as $o) {
            $meta = (array) ($o->meta ?? []);
            // A queued job is stored as 'manual_service'; the customer bought a
            // named service, so the ledger has to say which one.
            $type = str_replace('_', ' ', (string) (
                $meta['manual_service_title']
                ?? $meta['type']
                ?? $o->service_id
                ?? 'order'
            ));
            $balances = $orderBalanceMap[(int) $o->id] ?? [];

            // A key purchase is only worth reopening for the key, so the row says
            // what is behind the link.
            $hasKeys = \App\Support\IssuedKeys::forOrder($o) !== [];

            $rows[] = [
                'id' => (int) $o->id,
                'cells' => [
                    'description' => ucfirst($type),
                    'reference' => (string) ($o->customer_ref ?? ''),
                    'amount' => $money((int) $o->amount),
                    'before' => $money(isset($balances['before_kobo']) ? (int) $balances['before_kobo'] : null),
                    'after' => $money(isset($balances['after_kobo']) ? (int) $balances['after_kobo'] : null),
                    'status' => $badge((string) ($o->status ?? 'pending')),
                    'date' => optional($o->created_at)->format('Y-m-d, h:i:s A') ?? '',
                ],
                'action' => [
                    'label' => $hasKeys ? 'View keys' : 'View receipt',
                    'href' => route('vtu.receipt', $o->id),
                ],
            ];
        }
    @endphp

    <div class="reference-flow-page mx-auto max-w-5xl space-y-6">
        <section class="app-section p-4 sm:p-6">
            <x-records-table
                :columns="$columns"
                :rows="$rows"
                :compact="['description', 'amount', 'status', 'date']"
                :total="$totalRecords"
                :page="$orders->currentPage()"
                :pages="$orders->lastPage()"
                :per-page="$perPage"
                :search="$search"
                search-placeholder="Search by Tracking ID, NIN or BVN"
                empty-text="No transactions yet."
            />

            <div class="mt-5">
                {{ $orders->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
