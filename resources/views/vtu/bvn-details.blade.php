{{--
    The full BVN record, shown once.

    Everything the provider answers goes on this page, and the rows it does not
    answer for are shown as an em dash rather than hidden, so a customer reading
    it knows the difference between "not on your record" and "we did not ask".
--}}
<x-app-layout>
    @php
        $photo = $record['photo'] ?? '';
        $issuedAt = $record['issued_at'] ?? null;
        $record = $record['normalized'];
        $fullName = trim(implode(' ', array_filter([
            $record['first_name'] ?? '',
            $record['middle_name'] ?? '',
            $record['last_name'] ?? '',
        ], static fn ($part) => trim((string) $part) !== '' && trim((string) $part) !== '-')));

        $rows = [
            ['First Name', $record['first_name'] ?? '-'],
            ['Last Name', $record['last_name'] ?? '-'],
            ['Middle Name', $record['middle_name'] ?? '-'],
            ['Gender', $record['gender'] ?? '-'],
            ['Date Of Birth', $record['date_of_birth'] ?? '-'],
            ['Phone Number', $record['phone_number'] ?? '-'],
            ['Residential Address', $record['residential_address'] ?? '-'],
            ['Title', $record['title'] ?? '-'],
            ['Marital Status', $record['marital_status'] ?? '-'],
            ['Registration Date', $record['registration_date'] ?? '-'],
            ['Alternate Number', $record['alt_number'] ?? '-'],
            ['Email', $record['email'] ?? '-'],
            ['Nationality', $record['nationality'] ?? '-'],
            ['State Of Origin', $record['state_of_origin'] ?? '-'],
            ['LGA Of Origin', $record['lga_of_origin'] ?? '-'],
            ['State Of Residence', $record['state_of_residence'] ?? '-'],
            ['LGA Of Residence', $record['lga_of_residence'] ?? '-'],
            ['NIN', $record['nin'] ?? '-'],
            ['Watchlisted', $record['watchlisted'] ?? '-'],
        ];

        $dash = static fn ($value) => trim((string) $value) === '' ? '—' : trim((string) $value);
    @endphp

    <x-page-hero class="reference-shared-banner" title="BVN Details"
                 subtitle="Check every line before you leave. This is the record held against the number you verified." />

    <div class="reference-flow-page mx-auto max-w-3xl">
        <div class="app-section p-5 sm:p-7">
            <div class="flex flex-wrap items-center gap-4">
                @if($photo !== '')
                    <img src="{{ $photo }}" alt="Photo on the BVN record"
                         class="h-24 w-24 rounded-2xl border border-slate-200 object-cover">
                @endif
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl sm:text-2xl font-black text-slate-900">{{ $fullName ?: 'Name not on record' }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="app-choice-chip">BVN: {{ $dash($record['bvn'] ?? '') }}</span>
                        <span class="app-choice-chip">Checked {{ optional($issuedAt)->format('d M Y, H:i') }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($rows as [$label, $value])
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <div class="app-record-label">{{ $label }}</div>
                        <div class="app-record-value break-words">{{ $dash($value) }}</div>
                    </div>
                @endforeach
            </div>

            <p class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold leading-6 text-rose-700">
                Make sure you finish checking the details before going back — they close when you leave this page.
            </p>

            <form method="POST" action="{{ $ackUrl }}" class="mt-5">
                @csrf
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn-primary">I've Seen the Details. Go Back</button>
                    <a href="{{ route('vtu.bvn') }}" class="btn-soft">Cancel</a>
                </div>
            </form>

            <p class="mt-4 text-xs leading-6 text-slate-500">
                Want a copy of this record on paper? Print a BVN slip from
                <a href="{{ route('vtu.bvn') }}#bvnPrintForm" class="font-bold underline">BVN Services</a>,
                or open the <a href="{{ route('vtu.receipt', $order->id) }}" class="font-bold underline">receipt</a>
                for this check.
            </p>
        </div>
    </div>
</x-app-layout>
