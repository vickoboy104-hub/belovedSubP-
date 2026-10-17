{{--
    One NIN slip, drawn on a real A4 sheet.

    The card artwork is the provider's own blank template and every word of the
    customer's data sits at the exact point the provider's PDF puts it, so the
    printed result is the same document at the same size. Nothing here is
    styled to look approximately like a slip; the numbers come from NinSlipLayout.
--}}
@php
    $mono = '"OCR B 10 BT", "OCR-B 10 Pitch", "OCRB10PitchBT", "OCR B", "Courier New", monospace';
    $sans = '"Helvetica Neue", Helvetica, Arial, "Liberation Sans", sans-serif';
    $advance = fn (float $size): float => round($size * \App\Support\NinSlipLayout::MONOSPACE_ADVANCE_EM, 4);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: 210mm 297mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: #e5e7eb;
            color: #000;
            font-family: {{ $layout['font'] === 'monospace' ? $mono : $sans }};
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .nin-a4 {
            position: relative;
            width: 210mm;
            height: 297mm;
            margin: 6mm auto;
            background: #fff;
            overflow: hidden;
        }
        .nin-art,
        .nin-photo,
        .nin-qr,
        .nin-txt {
            position: absolute;
        }
        .nin-photo,
        .nin-qr { object-fit: fill; background: #fff; }
        .nin-txt { white-space: pre; font-weight: 400; line-height: 1; }
        .nin-cell { display: inline-block; text-align: center; }
        @media print {
            body { background: #fff; }
            .nin-a4 { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="nin-a4">
        <img class="nin-art"
             style="left: {{ $layout['artwork_rect']['x'] }}mm; top: {{ $layout['artwork_rect']['y'] }}mm; width: {{ $layout['artwork_rect']['w'] }}mm; height: {{ $layout['artwork_rect']['h'] }}mm;"
             src="{{ $artworkUrl }}"
             alt="">

        @if ($photo)
            <img class="nin-photo"
                 style="left: {{ $layout['photo_rect']['x'] }}mm; top: {{ $layout['photo_rect']['y'] }}mm; width: {{ $layout['photo_rect']['w'] }}mm; height: {{ $layout['photo_rect']['h'] }}mm;"
                 src="{{ $photo }}"
                 alt="">
        @endif

        @if ($layout['qr_rect'] && $qr)
            <img class="nin-qr"
                 style="left: {{ $layout['qr_rect']['x'] }}mm; top: {{ $layout['qr_rect']['y'] }}mm; width: {{ $layout['qr_rect']['w'] }}mm; height: {{ $layout['qr_rect']['h'] }}mm;"
                 src="{{ $qr }}"
                 alt="">
        @endif

        @foreach ($layout['fields'] as $field)
            @php
                $slot = $field['slot'];
                $isList = str_ends_with($slot, '_lines');
                $lines = $isList
                    ? array_map(null, $values['slots_list'][$slot] ?? [], array_keys($values['slots_list'][$slot] ?? []))
                    : [[$values['slots'][$slot] ?? '', 0]];

                // The card can only hold so many lines before the words run off it.
                $lines = array_slice($lines, 0, $layout['address_max_lines']);
                $monospace = $layout['font'] === 'monospace';
                $cellWidth = $advance($field['size']);
            @endphp

            @foreach ($lines as [$text, $lineIndex])
                @if ($text !== '')
                    @php
                        $top = round($field['top'] + ($isList ? $lineIndex * $layout['address_leading'] : 0), 3);
                        $run = $monospace
                            ? collect(str_split($text))->map(fn ($character) => '<span class="nin-cell" style="width: '.$cellWidth.'mm;">'.e($character).'</span>')->implode('')
                            : e($text);
                    @endphp
                    <span class="nin-txt" style="left: {{ $field['x'] }}mm; top: {{ $top }}mm; font-size: {{ $field['size'] }}mm;">{!! $run !!}</span>
                @endif
            @endforeach
        @endforeach
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
    </script>
</body>
</html>
