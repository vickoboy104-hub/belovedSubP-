{{--
    One BVN slip, drawn on a real A4 sheet.

    The provider that answers the check publishes no document of its own, so this
    is the slip: the customer's record, our name on it, and nothing about the
    systems behind it. It is a reprint of data the customer already paid to read,
    not a certificate from NIBSS or a bank, and the footer says so.
--}}
@php
    $photo = $record['photo'] ?? '';
    $reference = $record['reference'] ?? '';
    $issuedAt = $record['issued_at'] ?? now();
    $record = $record['normalized'];
    $sans = '"Helvetica Neue", Helvetica, Arial, "Liberation Sans", sans-serif';
    $dash = static fn ($value) => trim((string) $value) === '' ? '—' : trim((string) $value);

    $rows = [
        ['First Name', $record['first_name'] ?? ''],
        ['Middle Name', $record['middle_name'] ?? ''],
        ['Surname', $record['last_name'] ?? ''],
        ['Gender', $record['gender'] ?? ''],
        ['Date Of Birth', $record['date_of_birth'] ?? ''],
        ['Phone Number', $record['phone_number'] ?? ''],
        ['Alternate Number', $record['alt_number'] ?? ''],
        ['Email', $record['email'] ?? ''],
        ['Residential Address', $record['residential_address'] ?? ''],
        ['State Of Origin', $record['state_of_origin'] ?? ''],
        ['LGA Of Origin', $record['lga_of_origin'] ?? ''],
        ['Marital Status', $record['marital_status'] ?? ''],
    ];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BVN Slip {{ $record['bvn'] ?? '' }} · {{ site_name() }}</title>
    <style>
        @page { size: 210mm 297mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: #e5e7eb;
            color: #0f172a;
            font-family: {{ $sans }};
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .bvn-a4 {
            width: 190mm;
            margin: 10mm auto;
            padding: 14mm 13mm;
            background: #fff;
        }
        .bvn-bar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 6mm;
            border-bottom: .6mm solid #0f172a;
            padding-bottom: 4mm;
        }
        .bvn-bar h1 { margin: 0; font-size: 15pt; letter-spacing: .2mm; }
        .bvn-bar .bvn-sub { margin: 1.5mm 0 0; font-size: 9pt; color: #334155; }
        .bvn-issue { text-align: right; font-size: 9.5pt; font-weight: 700; white-space: nowrap; }
        .bvn-verdict {
            margin-top: 6mm;
            border: .4mm solid #0f172a;
            padding: 4mm 5mm;
            display: flex;
            align-items: center;
            gap: 5mm;
        }
        .bvn-verdict .bvn-mark {
            flex: 0 0 auto;
            width: 13mm; height: 13mm;
            border-radius: 50%;
            background: #166534;
            color: #fff;
            font-size: 18pt;
            line-height: 13mm;
            text-align: center;
        }
        .bvn-verdict strong { display: block; font-size: 13pt; }
        .bvn-verdict p { margin: 1.5mm 0 0; font-size: 10pt; }
        .bvn-figure {
            margin-top: 6mm;
            display: flex;
            gap: 6mm;
            align-items: flex-start;
        }
        .bvn-figure img {
            width: 34mm; height: 40mm;
            object-fit: cover;
            border: .3mm solid #94a3b8;
        }
        .bvn-figure .bvn-figure-note { font-size: 9.5pt; line-height: 5.5mm; }
        .bvn-table {
            width: 100%;
            margin-top: 6mm;
            border-collapse: collapse;
            font-size: 10pt;
        }
        .bvn-table caption {
            caption-side: top;
            text-align: left;
            font-size: 11pt;
            font-weight: 800;
            padding-bottom: 2mm;
        }
        .bvn-table th,
        .bvn-table td {
            border: .25mm solid #cbd5e1;
            padding: 2.2mm 3mm;
            text-align: left;
            vertical-align: top;
        }
        .bvn-table th { width: 46mm; font-weight: 700; color: #334155; background: #f8fafc; }
        .bvn-foot {
            margin-top: 8mm;
            border-top: .3mm solid #cbd5e1;
            padding-top: 3mm;
            font-size: 8.5pt;
            line-height: 4.6mm;
            color: #334155;
        }
        .bvn-actions {
            width: 190mm;
            margin: 0 auto 8mm;
            padding-top: 6mm;
            display: flex;
            gap: 3mm;
            justify-content: flex-end;
        }
        .bvn-actions a,
        .bvn-actions button {
            font: inherit; font-size: 10pt; font-weight: 700;
            padding: 2.6mm 6mm;
            border-radius: 999px;
            border: .3mm solid #0f172a;
            background: #0f172a;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
        }
        .bvn-actions .bvn-quiet { background: #fff; color: #0f172a; }
        @media print {
            body { background: #fff; }
            .bvn-actions { display: none; }
            .bvn-a4 { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="bvn-actions">
        <a href="{{ route('vtu.bvn.slip', $order->id) }}" class="bvn-quiet">Back to slip page</a>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <main class="bvn-a4">
        <header class="bvn-bar">
            <div>
                <h1>{{ site_name() }} — BVN Verification Slip</h1>
                <p class="bvn-sub">Bank Verification Number Matching System</p>
            </div>
            <div class="bvn-issue">Date of Issue: {{ $issuedAt->format('D, d M Y h:i A') }}</div>
        </header>

        <section class="bvn-verdict">
            <span class="bvn-mark" aria-hidden="true">✓</span>
            <div>
                <strong>Verification successful</strong>
                <p>The Bank Verification Number {{ $dash($record['bvn'] ?? '') }} has successfully been verified.</p>
            </div>
        </section>

        <section class="bvn-figure">
            @if($photo !== '')
                <img src="{{ $photo }}" alt="Photograph held on the BVN record">
            @endif
            <div class="bvn-figure-note">
                <p style="margin:0"><strong>Reference:</strong> {{ $reference !== '' ? $reference : '—' }}</p>
                <p style="margin:1.5mm 0 0"><strong>Verification type:</strong> By BVN number</p>
                <p style="margin:1.5mm 0 0"><strong>NIN on record:</strong> {{ $dash($record['nin'] ?? '') }}</p>
            </div>
        </section>

        <table class="bvn-table">
            <caption>Verification Data</caption>
            <tbody>
                <tr><th scope="row">BVN</th><td>{{ $dash($record['bvn'] ?? '') }}</td></tr>
                @foreach($rows as [$label, $value])
                    <tr><th scope="row">{{ $label }}</th><td>{{ $dash($value) }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <footer class="bvn-foot">
            <p style="margin:0">
                This slip is a reprint of the record held against the number above, produced by
                {{ site_name() }} from the identity service it pays for. It is not issued or endorsed by
                NIBSS, the NIMC or any bank, and it is not an identity card. Do not print it on plastic.
            </p>
            <p style="margin:1.5mm 0 0">
                Reference {{ $reference !== '' ? $reference : '—' }} · Issued {{ $issuedAt->format('d M Y') }} ·
                Verify again at {{ parse_url(url('/'), PHP_URL_HOST) ?: 'this site' }}
            </p>
        </footer>
    </main>
</body>
</html>
