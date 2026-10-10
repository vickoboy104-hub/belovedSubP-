<?php

namespace App\Support;

use App\Models\Order;

/**
 * The keys a customer paid for: an exam scratch card's serial number and PIN,
 * an electricity meter token.
 *
 * Two things feed a receipt. The provider's own answer, which nobody documented -
 * Gsubz names these fields differently per service and the site stores the whole
 * response blind - and whatever the owner types in by hand for a card he bought
 * outside the API, which is how most of these are still sold.
 */
class IssuedKeys
{
    public const MAX_KEYS = 6;

    public const MAX_LENGTH = 120;

    /** Purchases that hand something back, and so can be searched for it. */
    private const KEY_TYPES = ['exam', 'electricity'];

    /**
     * Provider field name (punctuation stripped, lowercased) => how the row
     * reads on the customer's receipt.
     */
    private const FIELD_LABELS = [
        'serialnumber' => 'Serial Number',
        'cardnumber' => 'Card Number',
        'pin' => 'PIN',
        'pincode' => 'PIN',
        'cardpin' => 'PIN',
        'scratchpin' => 'PIN',
        'activationcode' => 'Activation Code',
        'vouchernumber' => 'Voucher Number',
        'voucher' => 'Voucher Number',
        'token' => 'Token',
        'meterkey' => 'Token',
        'otp' => 'OTP',
    ];

    /** A card reads as a card: the number the customer types before the secret. */
    private const FIRST_LABELS = ['Serial Number', 'Card Number'];

    /**
     * Keys the provider answered with, or the ones the owner issued, ready to
     * copy, download and print. Empty means nothing was ever captured.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function forOrder(Order $order): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];

        $stored = self::normalize(is_array($meta['keys'] ?? null) ? $meta['keys'] : []);
        if ($stored !== []) {
            return $stored;
        }

        // Older orders only ever got the raw answer written into their metadata,
        // so a key that was paid for before this existed is still readable rather
        // than lost behind a re-buy.
        return self::fromResponses($order);
    }

    /**
     * What the provider answered this order with, whether or not keys were saved
     * from it - so the admin can see he is correcting the API, not a blank card.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function fromResponses(Order $order): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $exclude = self::exclusions($meta);

        $keys = self::scan(is_array($meta['verify_response'] ?? null) ? $meta['verify_response'] : [], $exclude);

        if ($keys === []) {
            $keys = self::scan(is_array($meta['provider_response'] ?? null) ? $meta['provider_response'] : [], $exclude);
        }

        return $keys;
    }

    /**
     * Mine the provider's answer for the card it carried and store it on the
     * order. Only the purchases that hand something back are searched: an
     * airtime answer has nothing to find and must not be picked over for one.
     */
    public static function capture(Order $order, array $providerResp, array $verifyResp = []): void
    {
        $meta = is_array($order->meta) ? $order->meta : [];

        if (!in_array((string) ($meta['type'] ?? ''), self::KEY_TYPES, true)) {
            return;
        }

        $exclude = self::exclusions($meta);

        $keys = self::scan($verifyResp, $exclude);
        if ($keys === []) {
            $keys = self::scan($providerResp, $exclude);
        }

        if ($keys !== []) {
            $order->meta = array_merge($meta, ['keys' => $keys]);
        }
    }

    /**
     * What the request itself carried, so a product name or phone number the
     * provider echoes back is never shown to the customer as something to save.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private static function exclusions(array $meta): array
    {
        $values = [
            (string) ($meta['pin_code'] ?? ''),
            (string) ($meta['phone'] ?? ''),
            (string) ($meta['customerID'] ?? ''),
            (string) ($meta['requestID'] ?? ''),
        ];

        return array_values(array_filter(array_map(
            static fn (string $value): string => self::clean($value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $exclude
     * @return array<int, array{label: string, value: string}>
     */
    private static function scan(array $response, array $exclude = []): array
    {
        $found = [];

        foreach (self::flatten($response) as $name => $value) {
            $label = self::FIELD_LABELS[self::normaliseName((string) $name)] ?? null;

            if ($label === null || !is_string($value) && !is_numeric($value)) {
                continue;
            }

            $clean = self::clean((string) $value);

            if (strlen($clean) < 4 || strlen($clean) > self::MAX_LENGTH || in_array($clean, $exclude, true)) {
                continue;
            }

            $found[$label] ??= $clean;
        }

        $rows = [];

        foreach (self::FIRST_LABELS as $first) {
            if (isset($found[$first])) {
                $rows[] = ['label' => $first, 'value' => $found[$first]];
                unset($found[$first]);
            }
        }

        foreach ($found as $label => $value) {
            $rows[] = ['label' => $label, 'value' => $value];
        }

        return array_slice($rows, 0, self::MAX_KEYS);
    }

    /**
     * What the admin typed, cleaned up for storage. Rows with no value are
     * dropped, and a row with no label is still a key, so it gets a number.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, value: string}>
     */
    public static function normalize(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = self::clean((string) ($row['value'] ?? ''));
            if ($value === '' || strlen($value) > self::MAX_LENGTH) {
                continue;
            }

            $label = mb_substr(self::clean((string) ($row['label'] ?? '')), 0, 40);

            $clean[] = ['label' => $label !== '' ? $label : 'Key', 'value' => $value];

            if (count($clean) >= self::MAX_KEYS) {
                break;
            }
        }

        foreach ($clean as $index => $row) {
            if ($row['label'] === 'Key') {
                $clean[$index]['label'] = 'Key '.($index + 1);
            }
        }

        return $clean;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function flatten(array $node, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $leaf = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $leaf = array_merge($leaf, self::flatten($value, $depth + 1));

                continue;
            }

            $leaf[$key] = $value;
        }

        return $leaf;
    }

    private static function normaliseName(string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    }
}
