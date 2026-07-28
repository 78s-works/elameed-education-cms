<?php

namespace App\Modules\Notifications\Sms;

/**
 * Normalizes a phone number to the format WE Connekio expects: international,
 * no leading `+` or `00` (e.g. `201XXXXXXXXX`). See the API manual, "Single SMS
 * Request" — `msisdn`. Egypt local `01XXXXXXXXX` is coerced to `201XXXXXXXXX`.
 */
final class Msisdn
{
    public static function normalize(string $raw): string
    {
        // Keep digits only — drops `+`, spaces, dashes, parentheses.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Strip an international-access prefix (00...).
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Egypt local `01XXXXXXXXX` (11 digits): drop the trunk `0`, prefix the
        // country code `20` → `201XXXXXXXXX`.
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '20'.substr($digits, 1);
        }

        return $digits;
    }
}
