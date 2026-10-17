<x-app-layout>
    <x-page-hero class="reference-shared-banner"
                 title="Transaction Explorer"
                 subtitle="Find one ledger row by customer, reference, order, channel or administrator - without moving any money." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        <section class="app-section p-5 sm:p-6">
            <form method="GET" action="{{ route('admin.wallet.transactions') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Search</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}"
                           placeholder="Reference, description, name, email or phone"
                           class="input-field mt-1">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Wallet transaction ID</span>
                    <input type="number" min="1" name="txn_id" value="{{ $filters['txn_id'] }}" class="input-field mt-1" placeholder="e.g. 4801">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">User ID</span>
                    <input type="number" min="1" name="user_id" value="{{ $filters['user_id'] }}" class="input-field mt-1" placeholder="e.g. 214">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Exact reference</span>
                    <input type="text" name="reference" value="{{ $filters['reference'] }}" class="input-field mt-1" placeholder="Full reference only">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Order ID</span>
                    <input type="number" min="1" name="order_id" value="{{ $filters['order_id'] }}" class="input-field mt-1" placeholder="Service order">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Administrator</span>
                    <select name="admin" class="input-field mt-1">
                        <option value="">Any administrator</option>
                        @foreach($administrators as $admin)
                            <option value="{{ $admin->id }}" @selected((int) $filters['admin'] === (int) $admin->id)>
                                #{{ $admin->id }} {{ $admin->name ?: 'Unnamed' }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Direction</span>
                    <select name="type" class="input-field mt-1">
                        <option value="">Credit and debit</option>
                        <option value="credit" @selected($filters['type'] === 'credit')>Credit in</option>
                        <option value="debit" @selected($filters['type'] === 'debit')>Debit out</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Status</span>
                    <select name="status" class="input-field mt-1">
                        <option value="">Any status</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
                        <option value="success" @selected($filters['status'] === 'success')>Success</option>
                        <option value="failed" @selected($filters['status'] === 'failed')>Failed</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Channel</span>
                    <select name="channel" class="input-field mt-1">
                        <option value="">Any channel</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel }}" @selected($filters['channel'] === $channel)>{{ $channel }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">From day</span>
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="input-field mt-1">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">To day</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="input-field mt-1">
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Order by</span>
                    <select name="sort" class="input-field mt-1">
                        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                        <option value="highest" @selected($filters['sort'] === 'highest')>Largest amount</option>
                        <option value="lowest" @selected($filters['sort'] === 'lowest')>Smallest amount</option>
                    </select>
                </label>

                <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-3">
                    <button type="submit" class="btn-primary flex-1 justify-center sm:flex-none">Apply filters</button>
                    @if($activeCount > 0)
                        <a href="{{ route('admin.wallet.transactions') }}" class="btn-outline justify-center px-4">Clear {{ $activeCount }}</a>
                    @endif
                </div>
            </form>

            <p class="mt-4 text-xs text-slate-500">
                This screen reads. Nothing on it credits, debits, refunds, freezes or blocks anything, and a failed row
                is shown as a failed row: a failure is a status, not a judgement about the customer who holds it.
                Identity numbers and phone digits inside a description are masked here and are available in full on the
                customer's own profile.
            </p>

            @if($orderNote)
                <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">
                    {{ $orderNote }}
                </p>
            @endif
        </section>

        <section class="app-section p-4 sm:p-6">
            @php
                $mask = '\App\Support\SensitiveFigures::mask';

                $statusTones = ['success' => 'success', 'failed' => 'danger', 'pending' => 'warning'];
                $isAuditOwner = in_array((int) auth()->id(), array_map('intval', (array) config('audit.owner_user_ids')), true);

                $columns = [
                    ['key' => 'id', 'label' => 'ID'],
                    ['key' => 'time', 'label' => 'Time'],
                    ['key' => 'customer', 'label' => 'Customer'],
                    ['key' => 'type', 'label' => 'Direction'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'channel', 'label' => 'Channel'],
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'description', 'label' => 'Description'],
                    ['key' => 'made_by', 'label' => 'Written by'],
                    ['key' => 'balance', 'label' => 'Wallet after'],
                    ['key' => 'user_id', 'label' => 'User ID'],
                    ['key' => 'wallet_id', 'label' => 'Wallet ID'],
                ];

                $rows = collect($transactions->items())->map(function ($t) use ($mask, $statusTones, $isAuditOwner): array {
                    $meta = (array) ($t->meta ?? []);
                    $user = $t->wallet?->user;
                    $fullName = trim(($user?->first_name ?? '').' '.($user?->last_name ?? ''));
                    $at = $t->created_at ? $t->created_at->timezone(config('app.timezone'))->format('d M Y, h:ia') : '—';

                    $actions = [['href' => $user ? route('admin.users.show', $user) : route('admin.wallet.transactions'), 'label' => 'Open customer']];

                    if ($isAuditOwner && $t->reference) {
                        $actions[] = [
                            'href' => route('admin.activity-log', ['q' => $t->reference]),
                            'label' => 'Trail for this reference',
                        ];
                    }

                    return [
                        'id' => $t->id,
                        'cells' => [
                            'id' => '#'.$t->id,
                            'time' => $at,
                            'customer' => ['value' => $fullName !== '' ? $fullName : ($user?->name ?: 'Unknown holder'), 'strong' => true],
                            'type' => strtoupper($t->type),
                            'amount' => ['value' => '₦'.number_format(((int) $t->amount) / 100, 2), 'strong' => true],
                            'status' => ['value' => ucfirst($t->status), 'tone' => $statusTones[$t->status] ?? ''],
                            'channel' => $t->channel ?: '—',
                            'reference' => ['value' => $t->reference ?: '—', 'copy' => true],
                            'description' => $mask($t->description) ?: '—',
                            'made_by' => isset($meta['admin_user_id'])
                                ? 'Administrator #'.$meta['admin_user_id'].' by hand'
                                : 'The customer or the gateway',
                            'balance' => isset($meta['balance_after_kobo'])
                                ? '₦'.number_format(((int) $meta['balance_after_kobo']) / 100, 2)
                                : '—',
                            'user_id' => $user?->id ? '#'.$user->id : '—',
                            'wallet_id' => $t->wallet_id ? '#'.$t->wallet_id : '—',
                        ],
                        'actions' => $actions,
                    ];
                })->all();
            @endphp

            <x-records-table
                :columns="$columns"
                :rows="$rows"
                :compact="['time', 'customer', 'amount', 'status']"
                :total="$transactions->total()"
                :page="$transactions->currentPage()"
                :pages="$transactions->lastPage()"
                :per-page="$perPage"
                :per-page-options="[10, 20, 50, 100]"
                :hidden="$activeFilters"
                :search="$filters['q']"
                search-placeholder="Reference, description or customer"
                empty-text="No ledger row matches these filters."
                caption="Wallet transactions matching the filters"
            />

            <div class="mt-6">
                {{ $transactions->appends(request()->query())->links() }}
            </div>

            <p class="mt-4 text-xs text-slate-500">
                {{ number_format($transactions->count()) }} of {{ number_format($transactions->total()) }} matching rows
                are on this page. The rest stay in the database until you ask for them - this screen never loads the
                whole ledger into a browser.
            </p>
        </section>
    </div>
</x-app-layout>
