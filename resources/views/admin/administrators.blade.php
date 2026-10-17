<x-app-layout>
    <x-page-hero class="reference-shared-banner"
                 title="All Administrators"
                 subtitle="Every account that can reach the admin area, and what the activity trail holds for each." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        @if(! $ownerConfigured)
            <section class="app-section border-amber-300 bg-amber-50 p-5 sm:p-6">
                <h2 class="text-base font-extrabold text-amber-900">No owner account is named yet</h2>
                <p class="mt-1 text-sm text-amber-900">
                    The activity trail is written for every admin action, but the audit owner list in
                    <code class="font-mono text-xs">AUDIT_OWNER_USER_IDS</code> is empty, so nobody - including the first
                    administrator - can read it. Naming the account is the owner's decision and is deliberately not
                    guessed here.
                </p>
            </section>
        @endif

        <section class="app-section p-4 sm:p-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Administrators</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($adminCount) }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Audit owners</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format(count($ownerIds)) }}</p>
                </div>
                <div class="col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Trail begins</p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        {{ $trailStart ? \Illuminate\Support\Carbon::parse($trailStart)->timezone(config('app.timezone'))->format('d M Y, H:i') : 'Nothing recorded yet' }}
                    </p>
                </div>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Activity counts cover only the period since the trail began. Anything an account did before that is
                not shown as zero - it is simply not recorded, and the "Last activity" column says so where no login
                was captured either.
            </p>

            <form method="GET" action="{{ route('admin.administrators') }}" class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="Name, email or phone" class="input-field" aria-label="Search administrators">
                <select name="scope" class="input-field" aria-label="Show which accounts">
                    <option value="admins" @selected($scope === 'admins')>Administrators</option>
                    <option value="members" @selected($scope === 'members')>Non-admin accounts</option>
                    <option value="all" @selected($scope === 'all')>Everyone</option>
                </select>
                <button type="submit" class="btn-primary justify-center">Filter</button>
                @if($search !== '' || $scope !== 'admins')
                    <a href="{{ route('admin.administrators') }}" class="btn-outline justify-center">Clear</a>
                @endif
            </form>

            @php
                $columns = [
                    ['key' => 'id', 'label' => 'ID'],
                    ['key' => 'name', 'label' => 'Account'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'admin_status', 'label' => 'Admin status'],
                    ['key' => 'role', 'label' => 'Role / permissions'],
                    ['key' => 'account_status', 'label' => 'Account status'],
                    ['key' => 'joined', 'label' => 'Registered'],
                    ['key' => 'login', 'label' => 'Last known login'],
                    ['key' => 'activity', 'label' => 'Last activity'],
                    ['key' => 'total', 'label' => 'Activities'],
                ];

                $format = fn ($stamp): string => $stamp
                    ? \Illuminate\Support\Carbon::parse($stamp)->timezone(config('app.timezone'))->format('d M Y, h:ia')
                    : '';

                $rows = collect($users->items())->map(function ($u) use ($stats, $ownerIds, $format): array {
                    $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
                    $stat = $stats->get($u->id);
                    $trailAt = $stat->last_activity_at ?? null;
                    $lastKnown = max((string) ($u->last_login_at ? $u->last_login_at->format('Y-m-d H:i:s') : ''), (string) ($trailAt ?? ''));

                    return [
                        'id' => $u->id,
                        'cells' => [
                            'id' => '#'.$u->id,
                            'name' => ['value' => $name !== '' ? $name : ($u->name ?: 'Unnamed account'), 'strong' => true],
                            'email' => $u->email,
                            'admin_status' => $u->is_admin
                                ? ['value' => 'Administrator', 'tone' => 'success']
                                : ['value' => 'Member', 'tone' => ''],
                            // There is no role table in this application: an account is either an administrator or
                            // it is not, and the one extra permission is the audit owner list. Anything else shown
                            // here would be a role the code does not have.
                            'role' => in_array((int) $u->id, $ownerIds, true)
                                ? ['value' => 'Audit owner', 'tone' => 'info']
                                : ['value' => $u->is_admin ? 'Full admin area' : 'No admin area', 'tone' => ''],
                            'account_status' => $u->email_verified_at
                                ? ['value' => 'Active', 'tone' => 'success']
                                : ['value' => 'Email unverified', 'tone' => 'warning'],
                            'joined' => $format($u->created_at),
                            'login' => $u->last_login_at
                                ? ['value' => $format($u->last_login_at), 'note' => $u->last_login_ip ?: 'No IP captured']
                                : 'Not recorded',
                            'activity' => $lastKnown !== '' ? $format($lastKnown) : 'Not recorded',
                            'total' => $stat ? number_format((int) $stat->activity_count) : '0',
                        ],
                        'action' => [
                            'href' => route('admin.activity-log', ['admin' => $u->id]),
                            'label' => 'View their history',
                        ],
                    ];
                })->all();
            @endphp

            <div class="mt-4">
                <x-records-table
                    :columns="$columns"
                    :rows="$rows"
                    :compact="['name', 'admin_status', 'activity', 'total']"
                    :total="$users->total()"
                    :page="$users->currentPage()"
                    :pages="$users->lastPage()"
                    :per-page="$perPage"
                    :hidden="['search' => $search, 'scope' => $scope]"
                    search-placeholder="Name, email or phone"
                    empty-text="No account matches this filter."
                />
            </div>

            <div class="mt-6">
                {{ $users->appends(request()->query())->links() }}
            </div>
        </section>
    </div>
</x-app-layout>
