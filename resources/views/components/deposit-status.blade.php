@props([
    'check' => [],
    'pending' => [],
    'title' => 'Deposit status',
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
            'body' => 'Deposits were asked about less than a minute ago. Try again shortly.',
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
        default => null,
    };

    $toneClasses = [
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'rose' => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
@endphp

@if($copy !== null || $pending->isNotEmpty())
    <section class="app-section p-5 sm:p-6" aria-labelledby="deposit-status-title">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="text-xl font-extrabold text-slate-900" id="deposit-status-title">{{ $title }}</div>
                <p class="mt-1 text-sm text-slate-500">Transfers into your account are confirmed by Flutterwave, not guessed at by this site.</p>
            </div>
            <form method="POST" action="{{ route('wallet.deposits.check') }}">
                @csrf
                <button type="submit" class="btn-primary w-full justify-center sm:w-auto">Check deposit</button>
            </form>
        </div>

        @if($copy !== null)
            <div class="mt-4 rounded-2xl border px-4 py-3 text-sm {{ $toneClasses[$copy['tone']] }}">
                <div class="font-bold">{{ $copy['head'] }}</div>
                <div class="mt-1">{{ $copy['body'] }}</div>
            </div>
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
