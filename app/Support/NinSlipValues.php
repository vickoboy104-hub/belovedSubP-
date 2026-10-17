<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns a stored NIN verification record into the words that go on a slip.
 *
 * The slip itself is drawn by NinSlipLayout; this only decides what each slot
 * says, so the two halves can be tested apart from the page.
 */
final class NinSlipValues
{
    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $providerData
     * @return array{slots: array<string, string>, slots_list: array{address_lines: list<string>}, photo: string, qr: string}
     */
    public static function forSlip(string $slipType, array $normalized, array $providerData, ?\DateTimeInterface $issuedAt = null): array
    {
        $issuedAt ??= now();

        $slots = [
            'surname' => self::upper(self::pick($normalized, 'last_name')),
            'first_name' => self::upper(self::pick($normalized, 'first_name')),
            'middle_name' => self::upper(self::pick($normalized, 'middle_name')),
            'given_names' => self::upper(trim(self::pick($normalized, 'first_name').' '.self::pick($normalized, 'middle_name'))),
            'birthdate' => self::date(self::pick($normalized, 'birthdate')),
            'gender' => self::upper(self::pick($normalized, 'gender')),
            'issue_date' => self::upper($issuedAt->format('d M Y')),
            'nin_grouped' => self::groupNin(self::digits(self::pick($normalized, 'nin'))),
            'nin_plain' => self::digits(self::pick($normalized, 'nin')),
            'tracking_id' => self::upper(self::pick($normalized, 'tracking_id')),
            'residence_state' => self::title(self::pick($normalized, 'residence_state')) ?: self::title(self::pick($normalized, 'state')),
        ];

        return [
            'slots' => $slots,
            'slots_list' => [
                'address_lines' => self::addressLines($normalized),
            ],
            'photo' => self::imageSrc($normalized['photo'] ?? ($providerData['photo'] ?? ($providerData['image'] ?? ''))),
            'qr' => self::qrSrc($normalized, $providerData),
        ];
    }

    /**
     * The long slip prints the street address as wrapped lines and keeps the
     * state on its own row, which is how the provider's own slip reads.
     *
     * 24 characters is the width that reproduces the sample: it breaks
     * "PLOT 163/164 NEW OGBEDE LAYOUT ENUGU" after OGBEDE, exactly as
     * BONIFACE_49979338424_Long_Slip.pdf does.
     *
     * @return list<string>
     */
    private static function addressLines(array $normalized): array
    {
        $parts = [
            self::pick($normalized, 'address_line_1'),
            self::pick($normalized, 'residence_town'),
            self::pick($normalized, 'residence_lga'),
        ];

        $text = self::upper(implode(' ', array_filter($parts, static fn ($part) => $part !== '')));

        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\R/', wordwrap($text, 24, "\n", true)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));
    }

    private static function qrSrc(array $normalized, array $providerData): string
    {
        foreach (['qr_code', 'qrcode', 'barcode', 'bar_code', 'qr'] as $key) {
            $src = self::imageSrc($providerData[$key] ?? '');

            if ($src !== '') {
                return $src;
            }
        }

        $payload = implode('|', [
            'NIN:'.self::digits(self::pick($normalized, 'nin')),
            'NAME:'.self::upper(self::pick($normalized, 'full_name')),
            'DOB:'.self::date(self::pick($normalized, 'birthdate')),
            'TRACKING:'.self::upper(self::pick($normalized, 'tracking_id')),
        ]);

        return 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='.rawurlencode($payload);
    }

    /**
     * The provider hands the photo over as bare base64, so it has to be turned
     * into a data URI before a browser can show it.
     */
    private static function imageSrc(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/\s+/', '', $value) ?? '');

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'data:') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || strlen($decoded) < 4) {
            return '';
        }

        $magic = substr($decoded, 0, 4);
        $mime = match (true) {
            str_starts_with($magic, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($magic, "\x89PNG") => 'image/png',
            str_starts_with($magic, 'GIF8') => 'image/gif',
            default => null,
        };

        return $mime === null ? '' : "data:{$mime};base64,".base64_encode($decoded);
    }

    private static function pick(array $normalized, string $key): string
    {
        $value = $normalized[$key] ?? '';

        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '-') ? '' : $value;
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function groupNin(string $digits): string
    {
        if (strlen($digits) === 11) {
            return substr($digits, 0, 4).' '.substr($digits, 4, 3).' '.substr($digits, 7, 4);
        }

        return $digits === '' ? '' : trim(preg_replace('/(\d{4})(?=\d)/', '$1 ', $digits) ?? $digits);
    }

    private static function date(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $matched = preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $iso)
            ?: preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $dmy);

        if ($matched) {
            if (isset($iso[1]) && strlen($iso[1]) === 4) {
                $date = self::safeDate((int) $iso[1], (int) $iso[2], (int) $iso[3]);
            } else {
                $date = self::safeDate((int) $dmy[3], (int) $dmy[2], (int) $dmy[1]);
            }

            if ($date !== '') {
                return $date;
            }
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? self::upper($value) : self::upper(date('d M Y', $timestamp));
    }

    private static function safeDate(int $year, int $month, int $day): string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 1900 || $year > 2200) {
            return '';
        }

        return self::upper(sprintf('%02d %s %04d', $day, [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ][$month], $year));
    }

    private static function upper(string $value): string
    {
        return Str::upper($value);
    }

    private static function title(string $value): string
    {
        return $value === '' ? '' : Str::title(Str::lower($value));
    }
}
