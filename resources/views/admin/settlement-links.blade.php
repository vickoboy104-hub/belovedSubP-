<x-app-layout>
    <x-page-hero
        class="reference-shared-banner"
        title="Settlement Declarations"
        subtitle="Where an admin states, in their own name, that one specific gateway charge was already paid to a customer by hand."
    />

    <div class="reference-flow-page mx-auto max-w-4xl space-y-6">
        <section class="app-section-muted p-5 sm:p-6">
            <p class="text-sm text-slate-600">
                A manual credit and a gateway charge that happen to carry the same amount, the same customer or the
                same account number are not the same money. Site policy has always been to hold an unverifiable
                transfer rather than pay on a guess, but holding a genuine new deposit behind unrelated old help is
                its own failure. So the link between the two is never inferred: an admin who has read both records
                states it here, against the one identifier Flutterwave gives a single charge, with a reason.
            </p>

            <p class="mt-3 text-sm text-slate-600">
                Once a charge is declared, a later report of that exact charge is held for a human instead of
                credited - and the release button on the held board refuses to pay it while the declaration stands.
                Nothing on this page moves money. Retracting a declaration is the only way back to the normal path,
                and the retraction needs its own note.
            </p>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('admin.settlement-links.index') }}"
                   class="app-choice-chip {{ $showRetracted ? '' : 'is-active' }}">Active ({{ $activeCount }})</a>
                <a href="{{ route('admin.settlement-links.index', ['status' => 'retracted']) }}"
                   class="app-choice-chip {{ $showRetracted ? 'is-active' : '' }}">Retracted ({{ $retractedCount }})</a>
            </div>
        </section>

        @if($links->isEmpty())
            <section class="app-section p-6">
                <p class="text-sm text-slate-500">
                    {{ $showRetracted
                        ? 'No declaration has been retracted.'
                        : 'No charge has been declared as settled by hand. Every arrival is being decided on its own evidence.' }}
                </p>
            </section>
        @endif

        @foreach($links as $link)
            @php
                $credit = $link->manualTransaction;
                $creditUser = $credit?->wallet?->user;
                $channelLabel = match ((string) ($credit?->channel ?? '')) {
                    'admin_manual_credit' => 'Fund-wallet tool',
                    'admin_wallet_adjustment' => 'Balance adjustment',
                    default => 'Manual credit',
                };
            @endphp
            <article class="app-section p-6 sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-lg font-extrabold text-slate-900">Declaration #{{ $link->id }}</div>
                        <div class="mt-1 text-sm text-slate-500">
                            declared {{ optional($link->declared_at)->diffForHumans() }}
                            by {{ $link->declaredBy?->name ?? 'a removed account' }}
                        </div>
                    </div>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                        @if($link->isRetracted()) bg-slate-100 text-slate-600 @else bg-emerald-50 text-emerald-700 @endif">
                        {{ $link->isRetracted() ? 'RETRACTED' : 'ACTIVE' }}
                    </span>
                </div>

                <div class="app-record-grid mt-4">
                    <div>
                        <div class="app-record-label">Gateway charge held by this</div>
                        <div class="app-record-value break-all">{{ $link->charge_token }}</div>
                        <button type="button" class="records-copy" data-copy-text="{{ $link->charge_token }}"
                                aria-label="Copy gateway charge identifier">Copy</button>
                    </div>
                    <div>
                        <div class="app-record-label">Identifier kind</div>
                        <div class="app-record-value">{{ $link->charge_token_kind === 'charge_id' ? 'Charge id' : 'flw_ref' }}</div>
                    </div>
                    <div>
                        <div class="app-record-label">Our reference on the charge</div>
                        <div class="app-record-value break-all">{{ $link->tx_ref ?: '—' }}</div>
                        @if($link->tx_ref)
                            <button type="button" class="records-copy" data-copy-text="{{ $link->tx_ref }}"
                                    aria-label="Copy our reference">Copy</button>
                        @endif
                    </div>
                    <div>
                        <div class="app-record-label">Manual credit it names</div>
                        <div class="app-record-value">
                            @if($credit)
                                Transaction #{{ $credit->id }} &middot; {{ $channelLabel }}
                                @if($creditUser)
                                    &middot; {{ $creditUser->name ?? 'Unnamed' }} (user ID {{ $creditUser->id }})
                                @else
                                    &middot; wallet #{{ $link->wallet_id }}
                                @endif
                            @else
                                Transaction #{{ $link->manual_transaction_id }} (no longer recorded)
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="app-record-label">Amount on that credit</div>
                        <div class="app-record-value">&#8358;{{ number_format($link->amount_kobo / 100, 2) }}</div>
                    </div>
                    <div>
                        <div class="app-record-label">Declared at</div>
                        <div class="app-record-value">{{ optional($link->declared_at)->format('d M Y, h:i A') }}</div>
                    </div>
                </div>

                <p class="mt-4 rounded-[18px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Why</span>
                    {{ $link->reason }}
                </p>

                @if($link->isRetracted())
                    <div class="mt-4 rounded-[18px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <div>Retracted {{ optional($link->retracted_at)->format('d M Y, h:i A') }}
                            by {{ $link->retractedBy?->name ?? 'a removed account' }}</div>
                        <div class="mt-1">{{ $link->retraction_note }}</div>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.settlement-links.retract', $link) }}" class="mt-5 space-y-3">
                        @csrf
                        <label class="block text-sm font-bold text-slate-700" for="retract-{{ $link->id }}-note">
                            Retract this declaration
                        </label>
                        <p class="text-xs text-slate-500">
                            Do this when the charge was not the money the credit paid. Once retracted, a fresh report
                            of the charge is processed normally again, so say what you found.
                        </p>
                        <textarea id="retract-{{ $link->id }}-note" name="note" rows="3" class="input-field" required
                                  placeholder="What showed that this link was wrong."></textarea>
                        <button type="submit" class="btn-danger">Retract declaration #{{ $link->id }}</button>
                    </form>
                @endif
            </article>
        @endforeach

        <div>{{ $links->links() }}</div>

        <section class="app-section p-6 sm:p-8">
            <h2 class="text-lg font-extrabold text-slate-900">Declare a charge as settled by hand</h2>
            <p class="mt-2 text-sm text-slate-600">
                Name one charge and one manual credit, and state what convinced you they are the same money. Copy the
                identifier from the payment record rather than typing it from memory - a declaration against a charge
                that did not happen holds that charge's real money later.
            </p>

            <form method="POST" action="{{ route('admin.settlement-links.store') }}" class="mt-5 space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-bold text-slate-700" for="held_deposit_id">
                        Point at a held deposit (optional)
                    </label>
                    <p class="mt-1 text-xs text-slate-500">
                        Preferred when the charge is held: its identifiers were written by the gateway itself. If you
                        pick one here, the charge fields below are ignored.
                    </p>
                    <select id="held_deposit_id" name="held_deposit_id" class="input-field mt-2">
                        <option value="">— not from a held deposit —</option>
                        @foreach($heldDeposits as $held)
                            <option value="{{ $held->id }}">
                                &#8358;{{ number_format($held->amount_kobo / 100, 2) }} &middot;
                                {{ $held->flutterwave_charge_id ?: ($held->flutterwave_id ?: 'no gateway id') }}
                                @if($held->tx_ref) &middot; {{ $held->tx_ref }} @endif
                            </option>
                        @endforeach
                    </select>
                    @if($heldDeposits->isEmpty())
                        <p class="mt-1 text-xs text-slate-500">Nothing is held right now.</p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-bold text-slate-700" for="charge_id">
                            Gateway charge id
                        </label>
                        <input type="text" id="charge_id" name="charge_id" class="input-field mt-2"
                               maxlength="191" autocomplete="off" placeholder="e.g. 41928375">
                        <p class="mt-1 text-xs text-slate-500">Flutterwave's id for this one charge. Not a reference this site issued.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700" for="flw_ref">
                            or flw_ref
                        </label>
                        <input type="text" id="flw_ref" name="flw_ref" class="input-field mt-2"
                               maxlength="191" autocomplete="off" placeholder="e.g. FLWM_...">
                        <p class="mt-1 text-xs text-slate-500">Use this only when the record carries no charge id.</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700" for="manual_transaction_id">
                        Which manual credit paid it
                    </label>
                    <select id="manual_transaction_id" name="manual_transaction_id" class="input-field mt-2" required>
                        <option value="">— choose a credit —</option>
                        @foreach($manualCredits as $credit)
                            @php $creditUser = $credit->wallet?->user; @endphp
                            <option value="{{ $credit->id }}">
                                #{{ $credit->id }} &middot; &#8358;{{ number_format($credit->amount / 100, 2) }} &middot;
                                {{ $credit->channel === 'admin_manual_credit' ? 'Fund-wallet tool' : 'Balance adjustment' }} &middot;
                                {{ $creditUser ? ($creditUser->name ?? 'Unnamed').' (ID '.$creditUser->id.')' : 'wallet #'.$credit->wallet_id }}
                                &middot; {{ optional($credit->created_at)->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                    @if($manualCredits->isEmpty())
                        <p class="mt-1 text-xs text-slate-500">
                            No hand-credited wallet transaction has been recorded yet, so there is nothing to declare
                            against. A declaration must name real money that already left the desk.
                        </p>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700" for="reason">
                        What proved they are the same money
                    </label>
                    <textarea id="reason" name="reason" rows="4" class="input-field mt-2" required maxlength="2000"
                              placeholder="Who paid, when, how the transfer reached us, and what the customer was told."></textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        Write it so a reader next year can hold this decision to account. Never paste an OTP, a BVN, a
                        NIN or a card or account secret in here.
                    </p>
                </div>

                <button type="submit" class="btn-primary" {{ $manualCredits->isEmpty() ? 'disabled' : '' }}>
                    Declare without paying
                </button>
            </form>
        </section>
    </div>
</x-app-layout>
