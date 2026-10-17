<?php

namespace App\Support;

/**
 * The one rule for hiding a long number in text that a human has to keep reading.
 *
 * Nine or more digits in a row is the shape of a NIN, a BVN, a card number or a
 * bank account. This site's own references carry letters, so they survive intact
 * and stay searchable.
 *
 * Groups are allowed between the digits, because that is how the sensitive numbers
 * are actually written: a card arrives as "5555 5555 5555 4444" and a phone as
 * "+234 801 234 5678". A rule that only looked at unbroken runs would keep those
 * two in full while hiding the NIN, which is the opposite of what the rule is for.
 */
final class SensitiveFigures
{
    public static function mask(?string $value): string
    {
        return (string) preg_replace_callback(
            '/\d(?:[ \-]?\d){8,}/',
            static function (array $match): string {
                $digits = (string) preg_replace('/\D/', '', $match[0]);

                return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
            },
            (string) $value
        );
    }
}
