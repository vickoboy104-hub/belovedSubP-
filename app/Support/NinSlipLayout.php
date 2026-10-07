<?php

namespace App\Support;

/**
 * The printed geometry of the three NIN slips.
 *
 * Every number here is lifted straight off the provider's own slip PDFs, which
 * are single-page A4 (595.28 x 841.89 pt). Each PDF is one blank card artwork
 * image plus the customer's variable data drawn as text at an absolute point
 * on the page, so reproducing those two things reproduces the slip.
 *
 * Coordinates are kept in PDF points - the unit the samples are written in -
 * and converted to millimetres only when they reach the page. PDF points are
 * measured from the bottom-left of the page; the page is measured from the
 * top-left, hence the flip in baselineMm().
 */
final class NinSlipLayout
{
    public const PAGE_WIDTH_PT = 595.28;
    public const PAGE_HEIGHT_PT = 841.89;

    /** OCR-B 10 Pitch BT, the font the samples embed, advances 602/1000 em. */
    public const MONOSPACE_ADVANCE_EM = 0.602;

    /** Where Chrome puts the baseline of a line-height:1 box, in ems. */
    public const BASELINE_OFFSET_EM = 0.847;

    private const SPECS = [
        // ESTHER_44075744122_Standard.pdf
        'standard_slip' => [
            'label' => 'Standard Slip',
            'artwork' => 'standard-card.jpg',
            'artwork_pt' => [48.75, 308.62, 483.84, 510.77],
            'photo_pt' => [172.13, 521.71, 61.65, 71.93],
            'qr_pt' => [346.90, 520.39, 70.00, 70.00],
            'font' => 'monospace',
            'fields' => [
                ['slot' => 'surname', 'x' => 239.04, 'y' => 575.62, 'size' => 9.0],
                ['slot' => 'given_names', 'x' => 239.04, 'y' => 555.46, 'size' => 9.0],
                ['slot' => 'birthdate', 'x' => 239.04, 'y' => 531.70, 'size' => 9.0],
                ['slot' => 'nin_grouped', 'x' => 207.36, 'y' => 488.75, 'size' => 22.2],
            ],
        ],

        // MUFTAU_38445620843_1787170052_Premium_Slip.pdf
        'premium_slip' => [
            'label' => 'Premium Slip',
            'artwork' => 'premium-card.jpg',
            'artwork_pt' => [135.00, 384.65, 317.52, 434.74],
            'photo_pt' => [186.00, 593.71, 54.02, 71.93],
            'qr_pt' => [362.25, 622.83, 70.56, 70.56],
            'font' => 'monospace',
            'fields' => [
                ['slot' => 'surname', 'x' => 249.12, 'y' => 644.76, 'size' => 8.0],
                ['slot' => 'given_names', 'x' => 249.12, 'y' => 623.88, 'size' => 8.0],
                ['slot' => 'birthdate', 'x' => 249.12, 'y' => 601.56, 'size' => 8.0],
                ['slot' => 'gender', 'x' => 325.44, 'y' => 601.56, 'size' => 8.0],
                ['slot' => 'issue_date', 'x' => 372.96, 'y' => 590.76, 'size' => 8.0],
                ['slot' => 'nin_grouped', 'x' => 221.04, 'y' => 554.92, 'size' => 22.5],
            ],
        ],

        // BONIFACE_49979338424_1790689943_Long_Slip.pdf
        // The long slip is set in Helvetica Neue, so it is proportional and the
        // address wraps into fixed lines with the state pinned to its own row.
        'long_slip' => [
            'label' => 'Long Slip',
            'artwork' => 'long-form.jpg',
            'artwork_pt' => [22.50, 557.38, 554.98, 262.01],
            'photo_pt' => [492.53, 649.89, 81.75, 107.25],
            'qr_pt' => null,
            'font' => 'sans',
            'address_leading_pt' => 14.4,
            'address_max_lines' => 4,
            'fields' => [
                ['slot' => 'tracking_id', 'x' => 90.00, 'y' => 739.90, 'size' => 7.0],
                ['slot' => 'nin_plain', 'x' => 90.00, 'y' => 711.11, 'size' => 8.0],
                ['slot' => 'surname', 'x' => 225.36, 'y' => 739.90, 'size' => 8.0],
                ['slot' => 'first_name', 'x' => 225.36, 'y' => 711.11, 'size' => 8.0],
                ['slot' => 'middle_name', 'x' => 225.36, 'y' => 683.02, 'size' => 8.0],
                ['slot' => 'gender', 'x' => 225.36, 'y' => 658.54, 'size' => 8.0],
                ['slot' => 'address_lines', 'x' => 341.28, 'y' => 733.42, 'size' => 7.0],
                ['slot' => 'residence_state', 'x' => 341.28, 'y' => 653.50, 'size' => 7.0],
            ],
        ],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::SPECS);
    }

    public static function has(string $slipType): bool
    {
        return isset(self::SPECS[$slipType]);
    }

    /**
     * How many address lines the slip's own field will hold before the words run
     * out of the printed card.
     */
    public static function maxAddressLines(string $slipType): int
    {
        return self::SPECS[$slipType]['address_max_lines'] ?? 1;
    }

    /**
     * The spec with every point measurement converted into millimetres measured
     * from the top-left corner of the A4 sheet.
     *
     * @return array<string, mixed>
     */
    public static function millimetres(string $slipType): array
    {
        $spec = self::SPECS[$slipType] ?? null;

        if ($spec === null) {
            throw new \InvalidArgumentException("Unknown slip type [{$slipType}].");
        }

        return [
            'label' => $spec['label'],
            'artwork' => $spec['artwork'],
            'font' => $spec['font'],
            'artwork_rect' => self::rect($spec['artwork_pt']),
            'photo_rect' => self::rect($spec['photo_pt']),
            'qr_rect' => $spec['qr_pt'] === null ? null : self::rect($spec['qr_pt']),
            'address_leading' => self::mm($spec['address_leading_pt'] ?? 0.0),
            'address_max_lines' => self::maxAddressLines($slipType),
            'fields' => array_map(static fn (array $field): array => [
                'slot' => $field['slot'],
                'x' => self::mm($field['x']),
                // The PDF places a text run by its baseline; the page places a
                // box by its top edge.
                'top' => round(self::baselineMm($field['y']) - self::BASELINE_OFFSET_EM * self::mm($field['size']), 3),
                'size' => round(self::mm($field['size']), 4),
            ], $spec['fields']),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box  x, y-from-bottom, width, height in pt
     * @return array<string, float>
     */
    private static function rect(array $box): array
    {
        [$x, $y, $width, $height] = $box;

        return [
            'x' => round(self::mm($x), 3),
            'y' => round(self::mm(self::PAGE_HEIGHT_PT - $y - $height), 3),
            'w' => round(self::mm($width), 3),
            'h' => round(self::mm($height), 3),
        ];
    }

    private static function baselineMm(float $yFromBottom): float
    {
        return self::mm(self::PAGE_HEIGHT_PT - $yFromBottom);
    }

    private static function mm(float $points): float
    {
        return $points * 25.4 / 72;
    }
}
