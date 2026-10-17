<x-app-layout>
    <x-page-hero class="reference-shared-banner"
                 :title="$focusAdmin ? 'Activity: '.$focusAdmin->name : 'Admin Activity Log'"
                 :subtitle="$focusAdmin ? 'Every action and refusal recorded for this account.' : 'What administrators did, what they tried, and why.'" />

    <div class="reference-flow-page mx-auto max-w-6xl space-y-6">
        @if($focusAdmin)
            <section class="app-section p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Account</p>
                        <p class="mt-1 text-base font-extrabold text-slate-900">#{{ $focusAdmin->id }} {{ $focusAdmin->name ?: 'Unnamed account' }}</p>
                        <p class="text-xs text-slate-500">
                            Registered {{ $focusAdmin->created_at ? $focusAdmin->created_at->timezone(config('app.timezone'))->format('d M Y') : 'date not recorded' }}
                            &middot; Last login {{ $focusAdmin->last_login_at ? $focusAdmin->last_login_at->timezone(config('app.timezone'))->format('d M Y, h:ia') : 'not recorded' }}
                        </p>
                    </div>
                    <a href="{{ route('admin.administrators') }}" class="btn-outline justify-center px-4">All administrators</a>
                </div>
            </section>
        @endif

        <section class="app-section p-4 sm:p-6">
            <div class="grid grid-cols-3 gap-3">
                @foreach(['success' => 'Completed', 'failed' => 'Failed', 'denied' => 'Refused'] as $key => $label)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-extrabold
                           {{ $key === 'success' ? 'text-emerald-700' : ($key === 'denied' ? 'text-rose-700' : 'text-amber-700') }}">
                            {{ number_format((int) ($counts[$key] ?? 0)) }}
                        </p>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500">Counts follow the filters below, across every page - not just the rows on screen.</p>

            <form method="GET" action="{{ route('admin.activity-log') }}" class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6">
                <select name="admin" class="input-field" aria-label="Administrator">
                    <option value="">Any administrator</option>
                    @foreach($administrators as $admin)
                        <option value="{{ $admin->id }}" @selected((int) $filters['admin'] === (int) $admin->id)>
                            #{{ $admin->id }} {{ $admin->name ?: 'Unnamed' }}
                        </option>
                    @endforeach
                </select>

                <select name="category" class="input-field" aria-label="Category">
                    <option value="">Any category</option>
                    @foreach(\App\Models\AdminActivityLog::CATEGORIES as $option)
                        <option value="{{ $option }}" @selected($filters['category'] === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                    @endforeach
                </select>

                <select name="result" class="input-field" aria-label="Result">
                    <option value="">Any result</option>
                    @foreach(\App\Models\AdminActivityLog::RESULTS as $option)
                        <option value="{{ $option }}" @selected($filters['result'] === $option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>

                <input type="date" name="from" value="{{ $filters['from'] }}" class="input-field" aria-label="From date">
                <input type="date" name="to" value="{{ $filters['to'] }}" class="input-field" aria-label="To date">

                <div class="flex gap-2">
                    <button type="submit" class="btn-primary flex-1 justify-center">Apply</button>
                    @if(array_filter($filters) !== [])
                        <a href="{{ route('admin.activity-log') }}" class="btn-outline justify-center px-3">Clear</a>
                    @endif
                </div>
            </form>

            @php
                $columns = [
                    ['key' => 'id', 'label' => 'ID'],
                    ['key' => 'time', 'label' => 'Time'],
                    ['key' => 'admin', 'label' => 'Administrator'],
                    ['key' => 'category', 'label' => 'Category'],
                    ['key' => 'action', 'label' => 'Action'],
                    ['key' => 'result', 'label' => 'Result'],
                    ['key' => 'target', 'label' => 'Affected record'],
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'description', 'label' => 'Description'],
                    ['key' => 'reason', 'label' => 'Reason'],
                    ['key' => 'changes', 'label' => 'What changed'],
                    ['key' => 'correlation', 'label' => 'Correlation ID'],
                    ['key' => 'ip', 'label' => 'IP address'],
                    ['key' => 'session', 'label' => 'Session / device'],
                ];

                $resultTones = ['success' => 'success', 'failed' => 'danger', 'denied' => 'warning'];

                $rows = collect($logs->items())->map(fn ($log): array => [
                    'id' => $log->id,
                    'cells' => [
                        'id' => '#'.$log->id,
                        'time' => $log->created_at ? $log->created_at->timezone(config('app.timezone'))->format('d M Y, h:ia') : '—',
                        'admin' => $log->admin_name !== null && $log->admin_name !== ''
                            ? ['value' => $log->admin_name, 'note' => $log->admin_user_id ? '#'.$log->admin_user_id : 'no account attached', 'strong' => true]
                            : ($log->admin_user_id ? '#'.$log->admin_user_id : 'No account attached'),
                        'category' => ucfirst(str_replace('_', ' ', $log->category)),
                        'action' => $log->action,
                        'result' => ['value' => ucfirst($log->result), 'tone' => $resultTones[$log->result] ?? ''],
                        'target' => $log->target_type ? $log->target_type.($log->target_id !== null ? ' #'.$log->target_id : '') : '—',
                        'reference' => $log->reference ?: '—',
                        'description' => $log->description ?: '—',
                        'reason' => $log->reason ?: '—',
                        'changes' => $log->changes
                            ? implode('; ', array_map(fn ($k, $v): string => $k.': '.(is_scalar($v) ? $v : json_encode($v)), array_keys((array) $log->changes), array_values((array) $log->changes)))
                            : '—',
                        'correlation' => $log->correlation_id ?: '—',
                        'ip' => $log->ip_address ?: '—',
                        'session' => trim(($log->session_hash ? 'session '.substr($log->session_hash, 0, 8) : '').' '.($log->user_agent ?: '')) ?: '—',
                    ],
                    'action' => $log->admin_user_id
                        ? ['href' => route('admin.activity-log', ['admin' => $log->admin_user_id]), 'label' => 'All their activity']
                        : null,
                ])->all();
            @endphp

            <div class="mt-4">
                <x-records-table
                    :columns="$columns"
                    :rows="$rows"
                    :compact="['time', 'admin', 'action', 'result']"
                    :total="$logs->total()"
                    :page="$logs->currentPage()"
                    :pages="$logs->lastPage()"
                    :per-page="$perPage"
                    :hidden="$filters"
                    search-placeholder="Action, reference or correlation ID"
                    empty-text="No activity matches these filters."
                />
            </div>

            <div class="mt-6">
                {{ $logs->appends(request()->query())->links() }}
            </div>

            <p class="mt-4 text-xs text-slate-500">
                Records are append-only: this page can read them, and nothing on it can edit or remove one. Passwords,
                tokens, secrets, full NIN/BVN numbers and payment credentials are never stored here - long digit runs
                are masked when the row is written, so a reference that identifies a customer's document stays
                unreadable in the trail.
            </p>
        </section>
    </div>
</x-app-layout>
