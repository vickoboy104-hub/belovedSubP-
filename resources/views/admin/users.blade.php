<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <section class="app-section p-6 sm:p-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="app-page-title text-[2rem] sm:text-[2.5rem]">User Accounts</h1>
                    <p class="app-page-subtitle">Manage users, profile data, discounts, and recovery access.</p>
                </div>

                <form method="GET" action="{{ route('admin.users') }}" class="w-full lg:max-w-md">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto_auto]">
                        <input type="text"
                               name="search"
                               value="{{ $search ?? '' }}"
                               placeholder="Search email, phone, or username"
                               class="input-field">
                        <button class="btn-primary justify-center">Search</button>
                        @if(!empty($search))
                            <a href="{{ route('admin.users') }}" class="btn-outline justify-center">Clear</a>
                        @endif
                    </div>
                </form>
            </div>
        </section>

        <section class="app-section p-4 sm:p-6">
            <div class="space-y-4 md:hidden">
                @forelse($users as $u)
                    @php
                        $fullName = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
                        if ($fullName === '') $fullName = $u->name;
                    @endphp
                    <article class="app-record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-lg font-extrabold text-slate-900">#{{ $u->id }} {{ $fullName ?: 'Unnamed User' }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $u->email }}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 justify-end">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $u->is_admin ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $u->is_admin ? 'Admin' : 'User' }}
                                </span>
                                @if($u->id === auth()->id())
                                    <span class="text-xs text-slate-400">You</span>
                                @endif
                            </div>
                        </div>

                        <div class="app-record-grid">
                            <div>
                                <div class="app-record-label">Phone</div>
                                <div class="app-record-value">{{ $u->phone ?: '-' }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Joined</div>
                                <div class="app-record-value">{{ $u->created_at->format('d M Y') }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">First Name</div>
                                <div class="app-record-value">{{ $u->first_name ?: '-' }}</div>
                            </div>
                            <div>
                                <div class="app-record-label">Last Name</div>
                                <div class="app-record-value">{{ $u->last_name ?: '-' }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="app-record-label">Virtual Account</div>
                                <div class="app-record-value">
                                    @if($u->virtual_account_number)
                                        {{ $u->virtual_account_bank ?: 'Bank' }} - {{ $u->virtual_account_number }}
                                        <div class="mt-1 text-xs font-medium text-slate-500">{{ $u->virtual_account_name }}</div>
                                    @else
                                        Not generated
                                    @endif
                                </div>
                            </div>
                            <div class="col-span-2">
                                <div class="app-record-label">Last Login</div>
                                <div class="app-record-value">
                                    @if($u->last_login_at)
                                        {{ $u->last_login_at->format('d M Y, h:ia') }}
                                        <div class="mt-1 text-xs font-medium text-slate-500">{{ $u->last_login_ip ?: '-' }}</div>
                                    @else
                                        No login yet
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            <a href="{{ route('admin.users.show', $u) }}" class="btn-outline w-full justify-center">View Details</a>

                            <form method="POST" action="{{ route('admin.users.discount', $u) }}" class="grid grid-cols-[1fr_auto] gap-2">
                                @csrf
                                <input type="number" min="0" max="100" step="0.01" name="discount_percent"
                                       value="{{ old('discount_percent', $u->discount_percent ?? 0) }}"
                                       class="input-field" placeholder="Discount %">
                                <button class="btn-primary justify-center px-4">Update</button>
                            </form>

                            <form method="POST" action="{{ route('admin.users.admin', $u) }}">
                                @csrf
                                <input type="hidden" name="is_admin" value="{{ $u->is_admin ? '0' : '1' }}">
                                <button class="btn-primary w-full justify-center {{ $u->is_admin ? '!bg-rose-600 hover:!bg-rose-700' : '!bg-blue-600 hover:!bg-blue-700' }}"
                                        @disabled($u->id === auth()->id())>
                                    {{ $u->is_admin ? 'Remove Admin' : 'Make Admin' }}
                                </button>
                            </form>

                            <form method="POST"
                                  action="{{ route('admin.users.reset-password', $u) }}"
                                  onsubmit="return confirm('Generate a new temporary password for this user?');">
                                @csrf
                                <button class="btn-primary w-full justify-center !bg-amber-600 hover:!bg-amber-700">
                                    Generate Temp Password
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.users.fund-wallet', $u) }}" class="space-y-2">
                                @csrf
                                <input type="number" name="amount" min="1" step="0.01" required
                                       placeholder="Fund amount (N)"
                                       class="input-field">
                                <input type="text" name="note"
                                       placeholder="Reason (optional)"
                                       class="input-field">
                                <button class="btn-primary w-full justify-center !bg-emerald-600 hover:!bg-emerald-700">
                                    Fund Wallet
                                </button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-center text-sm text-slate-500">No users matched your search.</div>
                @endforelse
            </div>

            <div class="hidden overflow-x-auto rounded-2xl border border-slate-200 md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="p-4 text-left">User</th>
                            <th class="p-4 text-left">Contact</th>
                            <th class="p-4 text-left">Virtual Account</th>
                            <th class="p-4 text-left">Role</th>
                            <th class="p-4 text-left">Discount</th>
                            <th class="p-4 text-left">Last Login</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 text-slate-700">
                        @forelse($users as $u)
                            @php
                                $fullName = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
                                if ($fullName === '') {
                                    $fullName = $u->name;
                                }
                            @endphp
                            <tr class="hover:bg-slate-50 transition align-top">
                                <td class="p-4">
                                    <div class="font-bold text-slate-900">#{{ $u->id }} {{ $fullName ?: 'Unnamed User' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $u->email }}</div>
                                    <div class="text-xs text-slate-400 mt-1">Joined {{ $u->created_at->format('d M Y') }}</div>
                                </td>

                                <td class="p-4">
                                    <div class="text-slate-800">{{ $u->phone ?: '-' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">First name: {{ $u->first_name ?: '-' }}</div>
                                    <div class="text-xs text-slate-500">Last name: {{ $u->last_name ?: '-' }}</div>
                                </td>

                                <td class="p-4">
                                    @if($u->virtual_account_number)
                                        <div class="font-semibold text-slate-900">{{ $u->virtual_account_bank ?: 'Bank' }}</div>
                                        <div class="text-slate-800">{{ $u->virtual_account_number }}</div>
                                        <div class="text-xs text-slate-500 mt-1">{{ $u->virtual_account_name }}</div>
                                    @else
                                        <span class="text-slate-400">Not generated</span>
                                    @endif
                                </td>

                                <td class="p-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $u->is_admin ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ $u->is_admin ? 'Admin' : 'User' }}
                                        </span>
                                        @if($u->id === auth()->id())
                                            <span class="text-xs text-slate-400">You</span>
                                        @endif
                                    </div>
                                </td>

                                <td class="p-4">
                                    <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
                                        {{ number_format((float) ($u->discount_percent ?? 0), 2) }}%
                                    </span>
                                </td>

                                <td class="p-4 text-slate-600">
                                    @if($u->last_login_at)
                                        <div>{{ $u->last_login_at->format('d M Y, h:ia') }}</div>
                                        <div class="text-xs text-slate-400 mt-1">{{ $u->last_login_ip ?: '-' }}</div>
                                    @else
                                        <span class="text-slate-400">No login yet</span>
                                    @endif
                                </td>
                            </tr>

                            <tr class="bg-slate-50/60">
                                <td colspan="6" class="px-4 pb-4 pt-0">
                                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                                        <div class="grid gap-2 lg:grid-cols-[minmax(8rem,0.8fr)_minmax(17rem,1.4fr)_minmax(8rem,0.8fr)_minmax(10rem,0.9fr)_minmax(18rem,1.6fr)]">
                                            <a href="{{ route('admin.users.show', $u) }}"
                                               class="flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center text-xs font-bold text-slate-800 hover:bg-white">
                                                View Details
                                            </a>

                                            <form method="POST" action="{{ route('admin.users.discount', $u) }}" class="grid grid-cols-[1fr_auto] gap-2">
                                                @csrf
                                                <input type="number" min="0" max="100" step="0.01" name="discount_percent"
                                                       value="{{ old('discount_percent', $u->discount_percent ?? 0) }}"
                                                       class="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900">
                                                <button class="rounded-xl bg-[#17233d] px-3 py-2 text-xs font-bold text-white">
                                                    Update %
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('admin.users.admin', $u) }}">
                                                @csrf
                                                <input type="hidden" name="is_admin" value="{{ $u->is_admin ? '0' : '1' }}">
                                                <button class="flex min-h-10 w-full items-center justify-center rounded-xl px-3 py-2 text-xs font-bold text-white {{ $u->is_admin ? 'bg-rose-600 hover:bg-rose-700' : 'bg-blue-600 hover:bg-blue-700' }}"
                                                        @disabled($u->id === auth()->id())>
                                                    {{ $u->is_admin ? 'Remove Admin' : 'Make Admin' }}
                                                </button>
                                            </form>

                                            <form method="POST"
                                                  action="{{ route('admin.users.reset-password', $u) }}"
                                                  onsubmit="return confirm('Generate a new temporary password for this user?');">
                                                @csrf
                                                <button class="flex min-h-10 w-full items-center justify-center rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">
                                                    Generate Temp Password
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('admin.users.fund-wallet', $u) }}" class="grid gap-2 xl:grid-cols-[1fr_1fr_auto]">
                                                @csrf
                                                <input type="number" name="amount" min="1" step="0.01" required
                                                       placeholder="Fund amount (N)"
                                                       class="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900">
                                                <input type="text" name="note"
                                                       placeholder="Reason (optional)"
                                                       class="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900">
                                                <button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">
                                                    Fund Wallet
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-6 text-center text-slate-500">No users matched your search.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                {{ $users->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
