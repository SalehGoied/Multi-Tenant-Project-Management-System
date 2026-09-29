<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * E.164: a leading + and 2 to 15 digits, with the first digit not zero.
     */
    public const E164 = '/^\+[1-9]\d{1,14}$/';

    /**
     * Normalize to the E.164 shape stored on users.
     *
     * Formatting characters are stripped. A 00 international prefix becomes +.
     * The result is idempotent, so the rate limiter and the phone lookup agree.
     */
    public static function normalize(string $phone): string
    {
        $compact = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        $digits = preg_replace('/\D/', '', $compact) ?? '';

        if ($digits === '') {
            return '';
        }

        return '+'.$digits;
    }
}
