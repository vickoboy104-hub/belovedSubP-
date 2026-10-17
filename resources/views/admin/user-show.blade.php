<x-app-layout>
    @php
        $fullName = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = $user->name;
        }

        $wallet = $user->wallet;
        $walletBalanceKobo = (int) ($wallet?->balance ?? 0);
    @endphp

    <x-page-hero class="reference-shared-banner" title="#{{ $user->id }} {{ $fullName ?: 'Unnamed User' }}" subtitle="{{ $user->email }}{{ $user->phone ? ' | ' . $user->phone : '' }}">
        <a href="{{ route('admin.users') }}" class="reference-hero-action">Back to users</a>
    </x-page-hero>

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[360px]">
                <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Wallet Balance</div>
                    <div class="amount-fit mt-2 text-2xl font-extrabold text-slate-900">&#8358;{{ number_format($walletBalanceKobo / 100, 2) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Account role</div>
                    <div class="amount-fit mt-2 text-2xl font-extrabold {{ $user->isMasterAdmin() ? 'text-amber-700' : ($user->is_admin ? 'text-emerald-700' : 'text-slate-900') }}">{{ $user->accountRoleLabel() }}</div>
                    <div class="mt-1 break-words text-xs font-medium text-slate-500">
                        User ID <span class="font-mono">{{ $user->id }}</span> - the number an audit record and a
                        support note point at.
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <form method="POST" action="{{ route('admin.users.profile', $user) }}" class="app-section p-5 sm:p-6">
                    @csrf
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900">User Details</h2>
                            <p class="mt-1 text-sm text-slate-500">Names, contact details, and wallet funding account.</p>
                        </div>
                        @can('customers.edit')
                        <button class="btn-primary">Save Details</button>
                        @endcan
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Display Name</span>
                            <input name="name" value="{{ old('name', $user->name) }}" required class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Email</span>
                            <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">First Name</span>
                            <input name="first_name" value="{{ old('first_name', $user->first_name) }}" class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Last Name</span>
                            <input name="last_name" value="{{ old('last_name', $user->last_name) }}" class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Phone</span>
                            <input name="phone" value="{{ old('phone', $user->phone) }}" class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Virtual Account Provider</span>
                            <input name="virtual_account_provider" value="{{ old('virtual_account_provider', $user->virtual_account_provider) }}" class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Virtual Account Bank</span>
                            <input name="virtual_account_bank" value="{{ old('virtual_account_bank', $user->virtual_account_bank) }}" class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Virtual Account Number</span>
                            <input name="virtual_account_number" value="{{ old('virtual_account_number', $user->virtual_account_number) }}" class="input-field mt-1">
                        </label>
                        <label class="block md:col-span-2">
                            <span class="text-sm font-bold text-slate-700">Virtual Account Name</span>
                            <input name="virtual_account_name" value="{{ old('virtual_account_name', $user->virtual_account_name) }}" class="input-field mt-1">
                        </label>
                    </div>
                </form>

                @if($openFunding->isNotEmpty())
                    <section class="app-section p-5 sm:p-6">
                        <h2 class="text-xl font-extrabold text-slate-900">Open funding requests</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Credits the gateway reported that this wallet has not been given. Recording one as settled
                            is a note on the row: it credits nothing, debits nothing, and the reason is kept with the
                            name of whoever saved it. This list covers every open request for this customer, not only
                            the page of the ledger you are looking at.
                        </p>

                        <div class="mt-4 space-y-3">
                            @foreach($openFunding as $funding)
                                @php
                                    $fundingMeta = (array) ($funding->meta ?? []);
                                    $settled = ($fundingMeta['manually_settled'] ?? null) === true;
                                @endphp
                                <article class="rounded-2xl border p-4 {{ $settled ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50' }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="amount-fit text-lg font-extrabold text-slate-900">
                                                &#8358;{{ number_format(((int) $funding->amount) / 100, 2) }}
                                            </div>
                                            <div class="mt-1 text-xs font-bold {{ $settled ? 'text-emerald-800' : 'text-amber-900' }}">
                                                {{ $settled ? 'Marked settled by hand' : 'Not marked as settled' }}
                                            </div>
                                            <div class="mt-1 text-xs text-slate-600">
                                                Ledger #{{ $funding->id }} · {{ optional($funding->created_at)->format('d M Y, h:ia') }} · {{ $funding->channel }} · {{ $funding->status }}
                                            </div>
                                            <div class="mt-1 break-all text-xs text-slate-600">{{ $funding->reference ?: 'No reference recorded' }}</div>
                                        </div>

                                        @if($funding->reference)
                                            <button type="button" class="records-copy"
                                                    data-copy-text="{{ $funding->reference }}"
                                                    aria-label="Copy reference">Copy</button>
                                        @endif
                                    </div>

                                    @can('wallet.settlement.declare')
                                    <form method="POST"
                                          action="{{ route($settled ? 'admin.wallet.requests.unmark-settled' : 'admin.wallet.requests.mark-settled', $funding->id) }}"
                                          class="mt-3 space-y-2">
                                        @csrf
                                        <label class="block text-xs text-slate-600">
                                            <span class="font-bold">
                                                {{ $settled ? 'Why reopen it' : 'Where the customer was paid instead' }}
                                            </span>
                                            <span>(min 10 characters, kept on the record)</span>
                                            <textarea name="note" required minlength="10" maxlength="2000" rows="3"
                                                      class="input-field mt-1">{{ $fundingMeta['manually_settled_note'] ?? '' }}</textarea>
                                        </label>
                                        <button type="submit" class="btn-soft text-xs">
                                            {{ $settled ? 'Open it again' : 'Mark as settled' }}
                                        </button>
                                    </form>
                                    @endcan
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="app-section p-5 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900">Wallet Transactions</h2>
                            <p class="mt-1 text-sm text-slate-500">Credits, debits, references, and funding channels for this user.</p>
                        </div>
                        <a href="{{ route('admin.wallet.transactions', ['search' => $user->email]) }}" class="btn-soft">Open Full List</a>
                    </div>

                    @php
                        $mask = '\App\Support\SensitiveFigures::mask';
                        $ledgerTones = ['success' => 'success', 'failed' => 'danger', 'pending' => 'warning'];
                        $paged = $walletTransactions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
                        $ledgerItems = collect($paged ? $walletTransactions->items() : $walletTransactions);

                        $ledgerColumns = [
                            ['key' => 'time', 'label' => 'Time'],
                            ['key' => 'direction', 'label' => 'Direction'],
                            ['key' => 'amount', 'label' => 'Amount'],
                            ['key' => 'status', 'label' => 'Status'],
                            ['key' => 'channel', 'label' => 'Channel'],
                            ['key' => 'reference', 'label' => 'Reference'],
                            ['key' => 'description', 'label' => 'Description'],
                            ['key' => 'written_by', 'label' => 'Written by'],
                            ['key' => 'balance', 'label' => 'Wallet after'],
                            ['key' => 'ledger_id', 'label' => 'Ledger ID'],
                        ];

                        $ledgerRows = $ledgerItems->map(static function ($t) use ($mask, $ledgerTones): array {
                            $meta = (array) ($t->meta ?? []);

                            return [
                                'id' => 'ledger-'.$t->id,
                                'cells' => [
                                    'time' => optional($t->created_at)->format('d M Y, h:ia') ?: '—',
                                    'direction' => ['value' => strtoupper($t->type), 'strong' => true],
                                    'amount' => ['value' => '₦'.number_format(((int) $t->amount) / 100, 2), 'strong' => true],
                                    'status' => ['value' => ucfirst($t->status), 'tone' => $ledgerTones[$t->status] ?? ''],
                                    'channel' => $t->channel ?: '—',
                                    'reference' => ['value' => $t->reference ?: '—', 'copy' => true],
                                    'description' => $mask($t->description) ?: '—',
                                    'written_by' => isset($meta['admin_user_id'])
                                        ? 'Administrator #'.$meta['admin_user_id'].' by hand'
                                        : 'The customer or the gateway',
                                    'balance' => isset($meta['balance_after_kobo'])
                                        ? '₦'.number_format(((int) $meta['balance_after_kobo']) / 100, 2)
                                        : '—',
                                    'ledger_id' => '#'.$t->id,
                                ],
                            ];
                        })->all();
                    @endphp

                    <div class="mt-5">
                        <x-records-table
                            :columns="$ledgerColumns"
                            :rows="$ledgerRows"
                            :compact="['time', 'direction', 'amount', 'status']"
                            :total="$paged ? $walletTransactions->total() : $ledgerItems->count()"
                            :page="$paged ? $walletTransactions->currentPage() : 1"
                            :pages="$paged ? $walletTransactions->lastPage() : 1"
                            :searchable="false"
                            empty-text="No wallet transactions found."
                            caption="This customer's wallet transactions"
                        />
                    </div>

                    @if($paged)
                        <div class="mt-5">{{ $walletTransactions->appends(request()->except('wallet_page'))->links() }}</div>
                    @endif
                </section>

                <section class="app-section p-5 sm:p-6">
                    <h2 class="text-xl font-extrabold text-slate-900">Orders</h2>

                    @php
                        $orderColumns = [
                            ['key' => 'time', 'label' => 'Time'],
                            ['key' => 'order_id', 'label' => 'Order'],
                            ['key' => 'service', 'label' => 'Service'],
                            ['key' => 'recipient', 'label' => 'Top-up target'],
                            ['key' => 'amount', 'label' => 'Amount'],
                            ['key' => 'status', 'label' => 'Status'],
                        ];

                        $orderRows = collect($orders->items())->map(static function ($order) use ($ledgerTones): array {
                            return [
                                'id' => 'order-'.$order->id,
                                'cells' => [
                                    'time' => optional($order->created_at)->format('d M Y, h:ia') ?: '—',
                                    'order_id' => '#'.$order->id,
                                    'service' => ['value' => ucfirst(str_replace('-', ' ', (string) ($order->meta['type'] ?? '—'))), 'strong' => true],
                                    'recipient' => $order->customer_ref ?: '—',
                                    'amount' => ['value' => '₦'.number_format(((int) $order->amount) / 100, 2), 'strong' => true],
                                    'status' => ['value' => ucfirst($order->status), 'tone' => $ledgerTones[$order->status] ?? 'warning'],
                                ],
                            ];
                        })->all();
                    @endphp

                    <div class="mt-5">
                        <x-records-table
                            :columns="$orderColumns"
                            :rows="$orderRows"
                            :compact="['time', 'service', 'amount', 'status']"
                            :total="$orders->total()"
                            :page="$orders->currentPage()"
                            :pages="$orders->lastPage()"
                            :searchable="false"
                            empty-text="No orders found."
                            caption="This customer's orders"
                        />
                    </div>

                    <div class="mt-5">{{ $orders->appends(request()->except('orders_page'))->links() }}</div>
                </section>
            </div>

            <aside class="space-y-6">
                @canany(['customers.discount', 'admins.grant', 'customers.password_reset'])
                <section class="app-section p-5">
                    <h2 class="text-lg font-extrabold text-slate-900">Admin Controls</h2>
                    <div class="mt-4 space-y-3">
                        @can('customers.discount')
                        <form method="POST" action="{{ route('admin.users.discount', $user) }}" class="space-y-2">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Discount Percent</span>
                                <input type="number" min="0" max="100" step="0.01" name="discount_percent" value="{{ old('discount_percent', $user->discount_percent ?? 0) }}" class="input-field mt-1">
                            </label>
                            <button class="btn-primary w-full">Update Discount</button>
                        </form>
                        @endcan

                        @can('admins.grant')
                        <form method="POST" action="{{ route('admin.users.admin', $user) }}">
                            @csrf
                            <input type="hidden" name="is_admin" value="{{ $user->is_admin ? '0' : '1' }}">
                            <button class="w-full {{ $user->is_admin ? 'btn-danger' : 'btn-primary' }}" @disabled($user->id === auth()->id())>
                                {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
                            </button>
                        </form>
                        @endcan

                        @can('customers.password_reset')
                        <form method="POST"
                              id="resetPasswordForm"
                              action="{{ route('admin.users.reset-password', $user) }}"
                              data-confirm-sheet="confirmTempPassword"
                              data-confirm-details='{"Action":"Generate a temporary password","Effect":"This user must set a new one at the next sign-in."}'>
                            @csrf
                            <button class="btn-outline w-full">
                                Generate Temp Password
                            </button>
                        </form>
                        @endcan
                    </div>
                </section>
                @endcanany

                @canany(['wallet.credit', 'wallet.debit', 'wallet.balance.set'])
                <form method="POST" action="{{ route('admin.users.adjust-wallet', $user) }}" class="app-section p-5">
                    @csrf
                    <h2 class="text-lg font-extrabold text-slate-900">Wallet Adjustment</h2>
                    <p class="mt-1 text-sm text-slate-500">Every change creates a wallet transaction record.</p>
                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Action</span>
                            <select name="adjustment_type" class="input-field mt-1">
                                <option value="credit">Credit Wallet</option>
                                <option value="debit">Debit Wallet</option>
                                <option value="set">Set Exact Balance</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Amount (N)</span>
                            <input type="number" name="amount" min="0.01" step="0.01" required class="input-field mt-1">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold text-slate-700">Reason (required)</span>
                            <input name="note" required minlength="10" maxlength="255"
                                   placeholder="Why this change is owed - at least 10 characters" class="input-field mt-1">
                        </label>
                        <button class="btn-primary w-full">Apply Wallet Change</button>
                    </div>
                </form>
                @endcanany

                <section class="app-section p-5">
                    <h2 class="text-lg font-extrabold text-slate-900">Account Summary</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="font-bold text-slate-500">Joined</dt>
                            <dd class="mt-1 text-slate-900">{{ optional($user->created_at)->format('d M Y, h:ia') }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-500">Last Login</dt>
                            <dd class="mt-1 text-slate-900">{{ $user->last_login_at ? $user->last_login_at->format('d M Y, h:ia') : 'No login yet' }}</dd>
                            <dd class="table-token mt-1 text-xs text-slate-500">{{ $user->last_login_ip ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-500">Referrer</dt>
                            <dd class="mt-1 text-slate-900">{{ $user->referrer?->email ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-500">Referrals</dt>
                            <dd class="mt-1 text-slate-900">{{ $referralsCount }} total, {{ $qualifiedReferralsCount }} active</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-500">Referral Earnings</dt>
                            <dd class="amount-fit mt-1 text-slate-900">&#8358;{{ number_format(((int) ($user->referral_earnings_balance ?? 0)) / 100, 2) }} balance</dd>
                            <dd class="amount-fit mt-1 text-xs text-slate-500">&#8358;{{ number_format(((int) ($user->referral_earnings_total ?? 0)) / 100, 2) }} total earned</dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </section>
    </div>

    <x-confirm-modal id="confirmTempPassword" title="Confirm Password Reset" confirmText="Generate Password" />
</x-app-layout>
