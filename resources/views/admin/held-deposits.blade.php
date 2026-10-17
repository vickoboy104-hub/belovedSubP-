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

            <p class="mt-3 text-xs text-slate-500">
                The payer's account number is shown in full on this board and nowhere else in the admin area, because
                it is the one figure that decides the case: masking it would leave an agent guessing whose money this
                is, and a guess is how the wrong wallet gets credited.
            </p>

            @if(auth()->check() && auth()->user()->hasAdminPermission('wallet.settlement.declare'))
                <p class="mt-3 text-sm">
                    <a href="{{ route('admin.settlement-links.index') }}" class="font-bold text-slate-700 underline">
                        Settlement declarations
                    </a>
                    &mdash; if a charge here was already paid to the customer by hand, record that here instead of
                    releasing the hold on a match. A declared charge cannot be credited from this board.
                </p>
            @endif

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

                // Every reason the funding path can hold on has to read correctly here.
                // An agent who is told "belongs to a different customer" when the real
                // reason is a hand settlement will credit the wrong wallet.
                $reasonLabels = [
                    'shared_account_number' => 'More than one customer holds this account number',
                    'wrong_wallet' => 'This account number belongs to a different customer',
                    'already_settled_manually' => 'A credit on this wallet was marked as settled by hand',
                    'manual_settlement_declared' => 'An admin declared this exact charge already paid by hand',
                    'charge_previously_reported_failed' => 'This charge reached us before and was closed as failed',
                    'amount_below_requested' => 'Less landed than this deposit asked for',
                    'amount_below_fee' => 'What landed is smaller than the funding fee, so it cannot pay anything out',
                    'no_matching_customer' => 'No customer here matches this transfer at all - a person has to name the account',
                    'no_reference_in_webhook' => 'The gateway reported a completed charge with no reference of ours',
                ];

                $declaration = $declarations->get(trim((string) ($held->flutterwave_charge_id ?? '')))
                    ?: $declarations->get(trim((string) ($held->flutterwave_id ?? '')));
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
                            {{ $reasonLabels[$held->reason] ?? ('Held for a person to read (reason recorded: '.($held->reason ?: 'unspecified').')') }}
                        </div>
                    </div>
                    <div>
                        <div class="app-record-label">Account number</div>
                        <div class="app-record-value">{{ $held->virtual_account_number ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="app-record-label">Flutterwave charge</div>
                        <div class="app-record-value break-all">{{ $held->flutterwave_charge_id ?: ($held->flutterwave_id ?? '-') }}</div>
                        @if($held->flutterwave_charge_id || $held->flutterwave_id)
                            <button type="button" class="records-copy"
                                    data-copy-text="{{ $held->flutterwave_charge_id ?: $held->flutterwave_id }}"
                                    aria-label="Copy gateway charge id">Copy</button>
                        @endif
                    </div>
                    <div>
                        <div class="app-record-label">Our reference</div>
                        <div class="app-record-value break-all">{{ $held->tx_ref ?: '-' }}</div>
                        @if($held->tx_ref)
                            <button type="button" class="records-copy"
                                    data-copy-text="{{ $held->tx_ref }}"
                                    aria-label="Copy our reference">Copy</button>
                        @endif
                    </div>
                </div>

                @if($held->status === 'held')
                    <div class="mt-5 rounded-[18px] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm font-bold text-slate-900">
                            @if(count($holders) === 0)
                                No customer here can be matched to this transfer
                            @else
                                {{ count($holders) }} {{ count($holders) === 1 ? 'customer' : 'customers' }} this could belong to
                            @endif
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-slate-600">
                            @foreach($holders as $candidate)
                                <li>{{ $candidate->name }} &middot; {{ $candidate->email }}
                                    &middot; balance &#8358;{{ number_format((int) ($candidate->wallet?->balance ?? 0) / 100, 2) }}</li>
                            @endforeach
                        </ul>
                        @if(count($holders) === 0)
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Every account number, reference and customer record this transfer could have been traced
                                through came back empty, so there is nobody to choose between. Naming the account here is
                                a decision made from the bank side of it, and the record will say plainly that no
                                candidate was ever found.
                            </p>
                        @endif
                    </div>

                    @if($declaration)
                        <div class="mt-5 rounded-[18px] border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                            <div class="text-sm font-extrabold text-amber-900">
                                Declaration #{{ $declaration->id }} already records this charge as paid by hand.
                            </div>
                            <p class="mt-1">
                                Stated by {{ $declaration->declaredBy?->name ?? 'a removed account' }}
                                against {{ $declaration->charge_token_kind === 'charge_id' ? 'charge id' : 'flw_ref' }}
                                <span class="break-all font-mono text-xs">{{ $declaration->charge_token }}</span>:
                                {{ $declaration->reason }}
                            </p>
                            <p class="mt-2">
                                This hold cannot be released while that declaration stands, and it will not be released
                                by pressing the button again. Read both records, then retract the declaration on the
                                <a href="{{ route('admin.settlement-links.index') }}" class="font-bold underline">declarations page</a>
                                if you can say what makes it wrong.
                            </p>
                        </div>
                    @else
                        @can('wallet.held.resolve')
                        <form method="POST" action="{{ route('admin.wallet.deposits.held.credit', $held) }}" class="mt-4 space-y-3">
                            @csrf
                            @if(count($holders) > 0)
                                <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-user">Credit which customer</label>
                                <select id="held-{{ $held->id }}-user" name="user_id" class="input-field" required>
                                    @foreach($holders as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} &middot; {{ $candidate->email }}</option>
                                    @endforeach
                                </select>
                            @else
                                <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-user">
                                    Credit which customer - their User ID
                                </label>
                                <input type="number" min="1" step="1" id="held-{{ $held->id }}-user" name="user_id"
                                       value="{{ old('user_id') }}" required class="input-field"
                                       placeholder="User ID you have proved this to">
                            @endif

                            <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-note">What proved it</label>
                            <textarea id="held-{{ $held->id }}-note" name="note" rows="3" class="input-field" required
                                placeholder="Who paid, how you know, and any reference they gave you."></textarea>

                            <p class="text-xs text-slate-500">
                                If what you found is that this charge was already paid to the customer by hand, do not
                                release this hold - declare the settlement instead, or the customer is credited twice.
                            </p>

                            <button type="submit" class="btn-primary">Credit this customer</button>
                        </form>
                        @endcan
                    @endif

                    @can('wallet.held.resolve')
                    <form method="POST" action="{{ route('admin.wallet.deposits.held.dismiss', $held) }}" class="mt-4 space-y-3">
                        @csrf
                        <label class="block text-sm font-bold text-slate-700" for="held-{{ $held->id }}-dismiss">Not ours to credit</label>
                        <textarea id="held-{{ $held->id }}-dismiss" name="note" rows="2" class="input-field" required
                            placeholder="Why this transfer does not belong to any customer here."></textarea>

                        <button type="submit" class="btn-danger">Keep the record, credit nobody</button>
                    </form>
                    @endcan
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
