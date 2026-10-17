@php
    $allPermissions = \App\Support\AdminPermissions::all();
    $groups = \App\Support\AdminPermissions::grouped();
@endphp

<x-app-layout>
    <x-page-hero title="Master Admin Permissions"
                 subtitle="Which administrator is allowed to do which thing, and who signed the change." />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        @if($masterIds === [])
            <section class="app-section border-amber-300 bg-amber-50 p-5 sm:p-6">
                <h2 class="text-base font-extrabold text-amber-900">No Master Admin is named yet</h2>
                <p class="mt-1 text-sm text-amber-900">
                    This screen is reached only by the accounts the owner named in
                    <code class="font-mono text-xs">MASTER_ADMIN_USER_IDS</code> (or, while that is unset,
                    <code class="font-mono text-xs">AUDIT_OWNER_USER_IDS</code>). The list is never guessed from a name,
                    an email address or a database flag, because an account that could promote itself would make every
                    permission on this page decorative.
                </p>
            </section>
        @endif

        <section class="app-section p-4 sm:p-6">
            <h2 class="text-base font-extrabold text-slate-900">Who holds what</h2>
            <p class="mt-1 text-sm text-slate-600">
                Reading a customer's history, moving that customer's money and handing out admin access are three
                separate decisions. A person is limited to what is ticked for them, and the limit is enforced on the
                server: a link they typed, a form post they saved, or a page they bookmarked all reach the same refusal.
            </p>

            <form method="GET" action="{{ route('admin.permissions.index') }}" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto_auto]">
                <input type="search" name="q" value="{{ $search }}" placeholder="User ID or name"
                       class="input-field" aria-label="Search administrators by user ID or name">
                <button type="submit" class="btn-primary justify-center">Find</button>
                @if($search !== '')
                    <a href="{{ route('admin.permissions.index') }}" class="btn-outline justify-center">Clear</a>
                @endif
            </form>
        </section>

        @forelse($administrators as $admin)
            @php
                $isMaster = in_array((int) $admin->id, $masterIds, true);
                $heldSlugs = $held[(int) $admin->id] ?? [];
                $name = trim(($admin->first_name ?? '').' '.($admin->last_name ?? ''));
                $name = $name !== '' ? $name : ($admin->name ?: 'Unnamed account');
            @endphp

            <section class="app-section p-4 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                            Admin ID <span class="font-mono text-slate-900">#{{ $admin->id }}</span>
                            · {{ $admin->accountRoleLabel() }}
                        </p>
                        <h2 class="mt-1 break-words text-lg font-extrabold text-slate-900">{{ $name }}</h2>
                        <p class="break-words text-sm text-slate-600">{{ $admin->email }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            @if($admin->email_verified_at)
                                Active · registered {{ $admin->created_at?->format('d M Y') }}
                            @else
                                Email unverified · registered {{ $admin->created_at?->format('d M Y') }}
                            @endif
                        </p>
                    </div>

                    <p class="text-sm font-bold text-slate-900">
                        {{ $isMaster ? 'Every permission' : count($heldSlugs).' of '.count($allPermissions) }}
                    </p>
                </div>

                @if($isMaster)
                    <p class="mt-3 rounded-lg bg-slate-100 p-3 text-sm text-slate-700">
                        A Master Admin holds every permission and cannot be edited here, not even by another Master. The
                        role lives in the server configuration, so it cannot be granted, taken or lost through a website
                        screen.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.permissions.update', $admin) }}" class="mt-4 space-y-4">
                        @csrf

                        @foreach($groups as $group => $definitions)
                            <div>
                                <h3 class="text-xs font-extrabold uppercase tracking-[0.16em] text-slate-500">{{ $group }}</h3>
                                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach($definitions as $slug => $definition)
                                        <label class="permission-choice flex items-start gap-2 rounded-lg border border-slate-200 p-2.5">
                                            <input type="checkbox" name="permissions[]" value="{{ $slug }}"
                                                   class="mt-0.5 h-5 w-5 shrink-0"
                                                   @checked(in_array($slug, $heldSlugs, true))>
                                            <span class="min-w-0">
                                                <span class="block break-words text-sm font-bold text-slate-900">{{ $definition['label'] }}</span>
                                                <span class="block break-words text-xs text-slate-500">{{ $definition['description'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div>
                            <label for="reason-{{ $admin->id }}" class="block text-sm font-bold text-slate-900">
                                Why is this set changing?
                            </label>
                            <textarea id="reason-{{ $admin->id }}" name="reason" rows="2" required minlength="10"
                                      class="input-field mt-1"
                                      placeholder="What this person now needs, and what they no longer do."></textarea>
                            <p class="mt-1 text-xs text-slate-500">
                                Saved with the change itself, and written to the activity trail with who made it.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary justify-center">Save this account</button>
                        </div>
                    </form>
                @endif
            </section>
        @empty
            <section class="app-section p-5 text-sm text-slate-600">
                No administrator account matches that search.
            </section>
        @endforelse

        <div class="mt-2">
            {{ $administrators->links() }}
        </div>

        <section class="app-section p-4 sm:p-6">
            <h2 class="text-base font-extrabold text-slate-900">Every permission, and who holds it</h2>
            <p class="mt-1 text-sm text-slate-600">
                Master Admins are listed once at the top rather than under all
                {{ count($allPermissions) }} rows, because their access comes from configuration and no grant row.
            </p>

            @php $listedAdmins = collect($administrators->items())->reject(fn ($admin): bool => in_array((int) $admin->id, $masterIds, true)); @endphp

            <div class="mt-4 space-y-4">
                @foreach($allPermissions as $slug => $definition)
                    @php
                        $holders = $listedAdmins
                            ->filter(fn ($admin): bool => in_array($slug, $held[(int) $admin->id] ?? [], true))
                            ->map(fn ($admin): string => $admin->name.' (#'.$admin->id.')')
                            ->values()
                            ->all();
                    @endphp
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-sm font-extrabold text-slate-900">{{ $definition['label'] }}</p>
                        <p class="break-words font-mono text-xs text-slate-500">{{ $slug }}</p>
                        <p class="mt-1 break-words text-sm text-slate-700">
                            @if($holders === [])
                                Nobody outside the Master Admins.
                            @else
                                {{ implode(', ', $holders) }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
