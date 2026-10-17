{{--
    The page a customer lands on after paying for a BVN slip.

    The sheet itself is a separate address so the browser can print that document
    alone; here it is shown inside a frame so the slip can be read before it is
    taken away, and the link below opens it on its own.
--}}
<x-app-layout>
    @php
        $record = $record['normalized'];
        $fullName = trim(implode(' ', array_filter([
            $record['first_name'] ?? '',
            $record['middle_name'] ?? '',
            $record['last_name'] ?? '',
        ], static fn ($part) => trim((string) $part) !== '' && trim((string) $part) !== '-')));
    @endphp

    <x-page-hero class="reference-shared-banner" title="Slip Download"
                 subtitle="Your BVN slip is ready. Save it to your device or print it from here." />

    <div class="reference-flow-page mx-auto max-w-3xl">
        <div class="app-section p-5 sm:p-7">
            <div class="text-center">
                <h2 class="text-xl font-black text-slate-900">Your Slip is Ready</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">
                    @if($fullName !== '')
                        {{ $fullName }} · BVN {{ $record['bvn'] ?? '—' }}
                    @else
                        BVN {{ $record['bvn'] ?? '—' }}
                    @endif
                </p>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ $sheetUrl }}" target="_blank" rel="noopener" class="btn-primary">Download Slip</a>
                <a href="{{ route('vtu.bvn') }}" class="btn-soft">Go Back</a>
            </div>

            <p class="mt-3 text-center text-xs leading-6 text-slate-500">
                On a phone, open the slip and choose Share or Print, then Save as PDF.
                This slip can be opened again from this receipt, so it is never charged twice.
            </p>

            <iframe src="{{ $sheetUrl }}" title="BVN slip preview" loading="lazy"
                    class="mt-6 h-[68vh] w-full rounded-2xl border border-slate-200 bg-white"></iframe>

            <p class="mt-4 text-xs leading-6 text-slate-500">
                The slip is a reprint of your own record from BelovedSubP. It is not issued by
                NIBSS or your bank, and it is not an identity card.
                <a href="{{ route('vtu.receipt', $order->id) }}" class="font-bold underline">Open the receipt</a>.
            </p>
        </div>
    </div>
</x-app-layout>
