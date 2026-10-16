<x-app-layout>
    <x-page-hero
        class="reference-shared-banner"
        title="Held Deposits"
        subtitle="Bank transfers we can prove arrived, but cannot prove who they belong to."
    />

    <div class="reference-flow-page mx-auto max-w-4xl space-y-6">
        <section class="app-section-muted p-5 sm:p-6">
            <p class="text-sm text-slate-600">
                A virtual account number names an account, not a person. When one verified transfer can be
                matched to more than one customer - or to a customer other than the wallet it was routed to -
                nobody is credited. The transfer is kept here, whole, until a person reads it and names the
                account it belongs to. Nothing is deleted, and crediting it here is the same protected credit
                as any other deposit: one transfer can still only be paid once.
            </p>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('admin.wallet.deposits.held') }}"
                   class="app-choice-chip {{ $showResolved ? '' : 'is-active' }}">Held ({{ $heldCount }})</a>
                <a href="{{ route('admin.wallet.deposits.held', ['status' => 'resolved']) }}"
                   class="app-choice-chip {{ $showResolved ? 'is-active' : '' }}">Resolved ({{ $resolvedCount }})</a>
            </div>
        </section>

        @forelse($deposits as $held)
            @php
                $holders = collect($held->holder_user_ids ?? [])->map(fn ($id) => $candidates->get($id))->filter();
            @endphp
            <article class="app-section p-6 sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-lg font-extrabold text-slate-900">
                            &#8358;{{ number_format($held->amount_kobo / 100, 2) }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">
                            held {{ optional($held->created_at)->diffForHumans() }}
                            from {{ str_replace('_', ' ', $held->arrived_via) }}
                        </div>
                    </div>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                        @if($held->status === 'held') bg-amber-50 text-amber-700
                        @elseif($held->status === 'credited') bg-emerald-50 text-emerald-700
                        @else bg-slate-100 text-slate-600
                        @endif">
                        {{ strtoupper($held->status) }}
                    </span>
                </div>

                <div class="app-record-grid mt-4">
                    <div>
                        <div class="app-record-label">Why it is held</div>
                        <div class="app-record-value">
                            {{ $held->reason === 'shared_account_number'
                                ? 'More than one customer holds this account number'
                                : 'This account number belongs to a different customer' }}
                        </div>
                    </div>
                    <div>
                        <div class="app-record-label">Account number</div>
                        <div class="app-record-value">{{ $held->virtual_account_number ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="app-record-label">Flutterwave charge</div>
                        <div class="app-record-value break-all">{{ $held->flutterwave_charge_id ?: ($held->flutterwave_id ?? '-') }}</div>
                    </div>
                    <div>
                        <div class="app-record-label">Our reference</div>
                        <div class="app-record-value break-all">{{ $held->tx_ref ?: '-' }}</div>
                    </div>
                </div>

                @if($held->status === 'held')
                    <div class="mt-5 rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-bold text-slate-900">
                            {{ count($holders) }} {{ count($holders) === 1 ? 'customer' : 'customers' }} this could belong to
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-slate-600">
                            @foreach($holders as $candidate)
                                <li>{{ $candidate->name }} &middot; {{ $candidate->email }}
                                    &middot; balance &#8358;{{ number_format((int) ($candidate->wallet?->balance ?? 0) / 100, 2) }}</li>
                            @endforeach
                        </ul>
                    </div>

                    <form method="POST" action="{{ route('admin.wallet.deposits.held.credit', $held) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-user">Credit which customer</label>
                        <select id="held-{{ $held->id }}-user" name="user_id" class="input-field" required>
                            @foreach($holders as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }} &middot; {{ $candidate->email }}</option>
                            @endforeach
                        </select>

                        <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-note">What proved it</label>
                        <textarea id="held-{{ $held->id }}-note" name="note" rows="3" class="input-field" required
                            placeholder="Who paid, how you know, and any reference they gave you."></textarea>

                        <button type="submit" class="btn-primary">Credit this customer</button>
                    </form>

                    <form method="POST" action="{{ route('admin.wallet.deposits.held.dismiss', $held) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-dismiss">Not ours to credit</label>
                        <textarea id="held-{{ $held->id }}-dismiss" name="note" rows="2" class="input-field" required
                            placeholder="Why this transfer does not belong to any customer here."></textarea>

                        <button type="submit" class="btn-danger">Keep the record, credit nobody</button>
                    </form>
                @else
                    <div class="mt-5 rounded-[18px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <div>{{ optional($held->resolved_at)->format('d M Y, h:i A') }}
                            by {{ $held->resolvedBy?->name ?? 'an admin' }}</div>
                        @if($held->resolvedWallet)
                            <div class="mt-1">Credited to {{ $held->resolvedWallet->user?->name ?? 'a removed account' }}.</div>
                        @endif
                        <div class="mt-1">{{ $held->resolution_note }}</div>
                    </div>
                @endif
            </article>
        @empty
            <section class="app-section p-6">
                <p class="text-sm text-slate-500">
                    {{ $showResolved ? 'No resolved deposits recorded yet.' : 'Nothing is held. Every verified transfer this site found could be proved to one customer.' }}
                </p>
            </section>
        @endforelse

        <div>{{ $deposits->links() }}</div>
    </div>
</x-app-layout>
