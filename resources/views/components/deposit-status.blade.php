@props([
    'check' => [],
    'pending' => [],
    'title' => 'Deposit status',
    'asked' => false,
])

@php
    $check = (array) $check;
    $pending = collect($pending);
    $creditedKobo = (int) ($check['credited_kobo'] ?? 0);

    // What the last deposit check said, in the customer's words. A webhook only
    // arrives when Flutterwave can reach this site from the outside, so this page
    // asks Flutterwave directly instead of waiting for a call that may never come.
    $copy = match ((string) ($check['status'] ?? '')) {
        'credited' => [
            'tone' => 'emerald',
            'head' => 'Deposit received',
            'body' => 'N'.number_format($creditedKobo / 100, 2).' arrived and has been added to your wallet. A confirmation email is on its way to you.',
        ],
        'no_deposit_found' => [
            'tone' => 'amber',
            'head' => 'Still waiting for your transfer',
            'body' => 'Flutterwave has not reported the deposit yet. Banks can take a few minutes to release a transfer - this page asks again on its own, or press Check deposit below.',
        ],
        'too_soon' => [
            'tone' => 'amber',
            'head' => 'A check just ran',
            'body' => 'Deposits were asked about a few seconds ago. Give the bank a moment, then press Check deposit again.',
        ],
        'settled' => [
            'tone' => 'slate',
            'head' => 'Nothing is waiting on your account',
            'body' => 'No transfer is outstanding. The balance you see is every deposit Flutterwave has confirmed as yours - press Check deposit any time to ask again.',
        ],
        'lookup_failed' => [
            'tone' => 'amber',
            'head' => 'Flutterwave could not be reached',
            'body' => 'Your transfer may already be with them. This page asks again by itself; press Check deposit to ask right now.',
        ],
        'not_configured' => [
            'tone' => 'rose',
            'head' => 'Card and transfer deposits are switched off',
            'body' => 'This site has no Flutterwave payment keys configured, so a transfer cannot be confirmed. Contact support before sending money.',
        ],
        'no_wallet' => [
            'tone' => 'rose',
            'head' => 'Your wallet is missing',
            'body' => 'There is no wallet to receive this deposit. Contact support.',
        ],
        'nothing_to_check' => [
            'tone' => 'slate',
            'head' => 'Nothing was waiting to be checked',
            'body' => 'You have no open funding request, so there was no transfer for Flutterwave to confirm. Fund your wallet to be given an account number, transfer into it, then press Check deposit.',
        ],
        default => null,
    };

    $toneClasses = [
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'rose' => 'border-rose-200 bg-rose-50 text-rose-800',
        'slate' => 'border-slate-200 bg-slate-50 text-slate-700',
    ];

    // Opening the page also runs a quiet check, and most of the time that check
    // finds nothing to ask about. Saying so on every page load would be noise;
    // saying it after the customer pressed the button is the answer they wanted.
    if (!$asked && (string) ($check['status'] ?? '') === 'nothing_to_check') {
        $copy = null;
    }
@endphp

@if($copy !== null || $pending->isNotEmpty())
    <section class="app-section p-5 sm:p-6" aria-labelledby="deposit-status-title">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="text-xl font-extrabold text-slate-900" id="deposit-status-title">{{ $title }}</div>
                <p class="mt-1 text-sm text-slate-500">Transfers into your account are confirmed by Flutterwave, not guessed at by this site.</p>
            </div>
            {{-- Pressing this twice in a row must not cost the customer a check or
                 start a second one behind their back, so the button retires itself
                 for the length of the request it just made. --}}
            <form method="POST" action="{{ route('wallet.deposits.check') }}" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" class="btn-primary w-full justify-center sm:w-auto" :disabled="busy" :class="{ 'opacity-60': busy }" x-text="busy ? 'Checking…' : 'Check deposit'">Check deposit</button>
            </form>
        </div>

        @if($copy !== null)
            <div class="mt-4 rounded-2xl border px-4 py-3 text-sm {{ $toneClasses[$copy['tone']] }}">
                <div class="font-bold">{{ $copy['head'] }}</div>
                <div class="mt-1">{{ $copy['body'] }}</div>
            </div>

            {{-- A page reload that quietly repaints a card is easy to miss after
                 pressing a button, so the same words are put in front of the
                 customer as a dialog. One definition serves both, so they cannot
                 disagree about what the check found. --}}
            @if($asked)
                @php
                    // Blade's @json directive mis-parses a multi-line array, so the
                    // payload is assembled here and passed in one piece.
                    $dialogPayload = [
                        'tone' => ['emerald' => 'success', 'rose' => 'error', 'amber' => 'info', 'slate' => 'info'][$copy['tone']] ?? 'info',
                        'title' => $copy['head'],
                        'message' => $copy['body'],
                    ];
                @endphp
                <script>window.pendingDepositDialog = @json($dialogPayload);</script>
            @endif
        @endif

        @foreach($pending as $awaiting)
            <div class="app-record-card mt-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-base font-extrabold text-slate-900">
                            Awaiting ₦{{ number_format(((int) $awaiting->amount) / 100, 2) }}
                        </div>
                        <div class="mt-1 text-sm text-slate-500">{{ $awaiting->description }}</div>
                    </div>
                    <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">AWAITING</span>
                </div>
                <div class="app-record-grid mt-3">
                    <div>
                        <div class="app-record-label">Wallet gets</div>
                        <div class="app-record-value">
                            ₦{{ number_format(((int) ($awaiting->meta['credited_kobo'] ?? $awaiting->amount)) / 100, 2) }}
                        </div>
                    </div>
                    <div>
                        <div class="app-record-label">Requested</div>
                        <div class="app-record-value">{{ optional($awaiting->created_at)->format('M j, Y, g:ia') }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </section>
@endif
