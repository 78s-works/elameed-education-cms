<?php

namespace App\Modules\Notifications\Support;

/**
 * Renders a minor-unit amount for notification copy.
 *
 * Money is stored in minor units everywhere (piastres), but "‏تم استرداد 15000‏"
 * is not a sentence a student can read. This is presentation only — nothing
 * here is ever used for arithmetic or persisted.
 */
final class Money
{
    public static function format(int $minor, string $currency = 'EGP'): string
    {
        return number_format($minor / 100, 2).' '.strtoupper($currency);
    }
}
