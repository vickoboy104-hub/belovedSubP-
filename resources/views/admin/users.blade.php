<x-app-layout>
    <x-slot name="header">
        Users
    </x-slot>

    <div class="bg-white/5 border border-white/10 rounded-3xl p-6 sm:p-10 card-glow">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-extrabold">User Accounts</h2>
                <p class="text-white/60 mt-1">Manage users, profile data, discounts, and recovery access.</p>
            </div>
            <form method="GET" action="{{ route('admin.users') }}" class="w-full sm:w-auto">
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text"
                           name="search"
                           value="{{ $search ?? '' }}"
                           placeholder="Search email, phone, or username"
                           class="w-full sm:w-80 px-4 py-3 rounded-2xl bg-black/30 border border-white/10 text-white placeholder:text-white/40">
                    <button class="px-4 py-3 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white text-sm font-bold">
                        Search
                    </button>
                    @if(!empty($search))
                        <a href="{{ route('admin.users') }}"
                           class="px-4 py-3 rounded-2xl border border-white/10 hover:bg-white/10 text-white text-sm font-semibold text-center">
                            Clear
                        </a>
                    @endif
                </div>
            </form>
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 rounded-2xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 text-green-700 dark:text-green-200 break-words">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-4 rounded-2xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 text-red-700 dark:text-red-200 break-words">
                {{ session('error') }}
            </div>
        @endif

        <div class="overflow-x-auto rounded-2xl border border-white/10">
            <table class="min-w-full text-sm">
                <thead class="bg-black/30 text-white/70">
                    <tr>
                        <th class="p-4 text-left">User</th>
                        <th class="p-4 text-left">Contact</th>
                        <th class="p-4 text-left">Virtual Account</th>
                        <th class="p-4 text-left">Role</th>
                        <th class="p-4 text-left">Discount %</th>
                        <th class="p-4 text-left">Last Login</th>
                        <th class="p-4 text-left">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-white/10">
                    @forelse($users as $u)
                        @php
                            $fullName = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
                            if ($fullName === '') {
                                $fullName = $u->name;
                            }
                        @endphp
                        <tr class="hover:bg-white/5 transition align-top">
                            <td class="p-4">
                                <div class="font-bold">#{{ $u->id }} {{ $fullName ?: 'Unnamed User' }}</div>
                                <div class="text-white/60 text-xs mt-1">{{ $u->email }}</div>
                                <div class="text-white/50 text-xs mt-1">Joined {{ $u->created_at->format('d M Y') }}</div>
                            </td>

                            <td class="p-4">
                                <div class="text-white/80">{{ $u->phone ?: '-' }}</div>
                                <div class="text-xs text-white/60 mt-1">
                                    First name: {{ $u->first_name ?: '-' }}
                                </div>
                                <div class="text-xs text-white/60">
                                    Last name: {{ $u->last_name ?: '-' }}
                                </div>
                            </td>

                            <td class="p-4">
                                @if($u->virtual_account_number)
                                    <div class="font-semibold">{{ $u->virtual_account_bank ?: 'Bank' }}</div>
                                    <div class="text-white/80">{{ $u->virtual_account_number }}</div>
                                    <div class="text-xs text-white/60 mt-1">{{ $u->virtual_account_name }}</div>
                                @else
                                    <span class="text-white/50">Not generated</span>
                                @endif
                            </td>

                            <td class="p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $u->is_admin ? 'bg-green-500/20 text-green-200' : 'bg-white/10 text-white/60' }}">
                                        {{ $u->is_admin ? 'Admin' : 'User' }}
                                    </span>
                                    @if($u->id === auth()->id())
                                        <span class="text-xs text-white/50">You</span>
                                    @endif
                                </div>
                            </td>

                            <td class="p-4">
                                <form method="POST" action="{{ route('admin.users.discount', $u) }}" class="flex items-center gap-2">
                                    @csrf
                                    <input type="number" min="0" max="100" step="0.01" name="discount_percent"
                                           value="{{ old('discount_percent', $u->discount_percent ?? 0) }}"
                                           class="w-24 px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
                                    <button class="px-3 py-2 rounded-xl bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold">
                                        Update
                                    </button>
                                </form>
                            </td>

                            <td class="p-4 text-white/70">
                                @if($u->last_login_at)
                                    <div>{{ $u->last_login_at->format('d M Y, h:ia') }}</div>
                                    <div class="text-xs text-white/50 mt-1">{{ $u->last_login_ip ?: '-' }}</div>
                                @else
                                    <span class="text-white/50">No login yet</span>
                                @endif
                            </td>

                            <td class="p-4">
                                <div class="flex flex-col gap-2">
                                    <form method="POST" action="{{ route('admin.users.admin', $u) }}">
                                        @csrf
                                        <input type="hidden" name="is_admin" value="{{ $u->is_admin ? '0' : '1' }}">
                                        <button class="w-full px-3 py-2 rounded-xl {{ $u->is_admin ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700' }} text-white text-xs font-bold"
                                                @disabled($u->id === auth()->id())>
                                            {{ $u->is_admin ? 'Remove Admin' : 'Make Admin' }}
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action="{{ route('admin.users.reset-password', $u) }}"
                                          onsubmit="return confirm('Generate a new temporary password for this user?');">
                                        @csrf
                                        <button class="w-full px-3 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold">
                                            Generate Temp Password
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.users.fund-wallet', $u) }}" class="space-y-2">
                                        @csrf
                                        <input type="number" name="amount" min="1" step="0.01" required
                                               placeholder="Fund amount (N)"
                                               class="w-full px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-xs">
                                        <input type="text" name="note"
                                               placeholder="Reason (optional)"
                                               class="w-full px-3 py-2 rounded-xl bg-black/30 border border-white/10 text-white text-xs">
                                        <button class="w-full px-3 py-2 rounded-xl bg-green-600 hover:bg-green-700 text-white text-xs font-bold">
                                            Fund Wallet
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-6 text-center text-white/60">
                                No users matched your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>
