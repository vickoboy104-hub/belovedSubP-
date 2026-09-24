<x-app-layout>
    @php
        $fullName = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = $user->name;
        }

        $wallet = $user->wallet;
        $walletBalanceKobo = (int) ($wallet?->balance ?? 0);
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <a href="{{ route('admin.users') }}" class="text-sm font-bold text-slate-600 hover:text-slate-900">&larr; Back to users</a>
                    <h1 class="app-page-title mt-3 text-[2rem] sm:text-[2.5rem]">#{{ $user->id }} {{ $fullName ?: 'Unnamed User' }}</h1>
                    <p class="app-page-subtitle">{{ $user->email }} @if($user->phone) | {{ $user->phone }} @endif</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[360px]">
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Wallet Balance</div>
                        <div class="amount-fit mt-2 text-2xl font-extrabold text-slate-900">&#8358;{{ number_format($walletBalanceKobo / 100, 2) }}</div>
                    </div>
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Role</div>
                        <div class="mt-2 text-2xl font-extrabold {{ $user->is_admin ? 'text-emerald-700' : 'text-slate-900' }}">{{ $user->is_admin ? 'Admin' : 'User' }}</div>
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
                        <button class="btn-primary">Save Details</button>
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

                <section class="app-section p-5 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-extrabold text-slate-900">Wallet Transactions</h2>
                            <p class="mt-1 text-sm text-slate-500">Credits, debits, references, and funding channels for this user.</p>
                        </div>
                        <a href="{{ route('admin.wallet.transactions', ['search' => $user->email]) }}" class="btn-soft">Open Full List</a>
                    </div>

                    <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th class="p-3 text-left">Date</th>
                                    <th class="p-3 text-left">Type</th>
                                    <th class="p-3 text-left">Amount</th>
                                    <th class="p-3 text-left">Status</th>
                                    <th class="p-3 text-left">Channel</th>
                                    <th class="p-3 text-left">Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 text-slate-700">
                                @forelse($walletTransactions as $t)
                                    <tr>
                                        <td class="p-3">{{ optional($t->created_at)->format('d M Y, h:ia') }}</td>
                                        <td class="p-3 font-bold {{ $t->type === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">{{ strtoupper($t->type ?? '-') }}</td>
                                        <td class="amount-fit p-3 font-bold text-slate-900">&#8358;{{ number_format(((int) ($t->amount ?? 0)) / 100, 2) }}</td>
                                        <td class="p-3">{{ strtoupper($t->status ?? '-') }}</td>
                                        <td class="p-3">{{ $t->channel ?? '-' }}</td>
                                        <td class="table-token p-3 text-xs text-slate-500">{{ $t->reference ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-5 text-center text-slate-500">No wallet transactions found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($walletTransactions instanceof \Illuminate\Contracts\Pagination\Paginator)
                        <div class="mt-5">{{ $walletTransactions->appends(request()->except('wallet_page'))->links() }}</div>
                    @endif
                </section>

                <section class="app-section p-5 sm:p-6">
                    <h2 class="text-xl font-extrabold text-slate-900">Orders</h2>
                    <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th class="p-3 text-left">Date</th>
                                    <th class="p-3 text-left">ID</th>
                                    <th class="p-3 text-left">Service</th>
                                    <th class="p-3 text-left">Customer</th>
                                    <th class="p-3 text-left">Amount</th>
                                    <th class="p-3 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 text-slate-700">
                                @forelse($orders as $order)
                                    <tr>
                                        <td class="p-3">{{ optional($order->created_at)->format('d M Y, h:ia') }}</td>
                                        <td class="p-3 font-bold">#{{ $order->id }}</td>
                                        <td class="p-3 capitalize">{{ str_replace('-', ' ', $order->meta['type'] ?? '-') }}</td>
                                        <td class="table-token p-3">{{ $order->customer_ref }}</td>
                                        <td class="amount-fit p-3 font-bold text-slate-900">&#8358;{{ number_format(((int) $order->amount) / 100, 2) }}</td>
                                        <td class="p-3">{{ strtoupper($order->status ?? '-') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-5 text-center text-slate-500">No orders found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5">{{ $orders->appends(request()->except('orders_page'))->links() }}</div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="app-section p-5">
                    <h2 class="text-lg font-extrabold text-slate-900">Admin Controls</h2>
                    <div class="mt-4 space-y-3">
                        <form method="POST" action="{{ route('admin.users.discount', $user) }}" class="space-y-2">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-bold text-slate-700">Discount Percent</span>
                                <input type="number" min="0" max="100" step="0.01" name="discount_percent" value="{{ old('discount_percent', $user->discount_percent ?? 0) }}" class="input-field mt-1">
                            </label>
                            <button class="btn-primary w-full">Update Discount</button>
                        </form>

                        <form method="POST" action="{{ route('admin.users.admin', $user) }}">
                            @csrf
                            <input type="hidden" name="is_admin" value="{{ $user->is_admin ? '0' : '1' }}">
                            <button class="w-full rounded-2xl {{ $user->is_admin ? 'bg-rose-600 hover:bg-rose-700' : 'bg-blue-600 hover:bg-blue-700' }} px-4 py-3 text-sm font-bold text-white" @disabled($user->id === auth()->id())>
                                {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" onsubmit="return confirm('Generate a new temporary password for this user?');">
                            @csrf
                            <button class="w-full rounded-2xl bg-amber-600 px-4 py-3 text-sm font-bold text-white hover:bg-amber-700">
                                Generate Temp Password
                            </button>
                        </form>
                    </div>
                </section>

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
                            <span class="text-sm font-bold text-slate-700">Reason</span>
                            <input name="note" maxlength="255" placeholder="Required for audit clarity" class="input-field mt-1">
                        </label>
                        <button class="btn-primary w-full">Apply Wallet Change</button>
                    </div>
                </form>

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
</x-app-layout>
