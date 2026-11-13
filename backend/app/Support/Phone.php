<?php

namespace App\Support;

/** Phone normalisation, Nigeria-first (+234) but tolerant of any E.164 number. */
final class Phone
{
    private const COUNTRY_CODES = ['NG' => '234', 'GH' => '233', 'KE' => '254', 'ZA' => '27', 'GB' => '44', 'US' => '1'];

    public static function normalize(?string $raw, string $country = 'NG'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $hasPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        if ($hasPlus) {
            return '+'.$digits;
        }
        $cc = self::COUNTRY_CODES[strtoupper($country)] ?? null;
        if ($cc && str_starts_with($digits, $cc) && strlen($digits) > 10) {
            return '+'.$digits;
        }
        if ($cc && str_starts_with($digits, '0')) {
            return '+'.$cc.substr($digits, 1);
        }

        return $cc ? '+'.$cc.$digits : '+'.$digits;
    }

    /**
     * The trailing national significant digits (last 9 digits for most numbers), used to
     * find a number on a page regardless of how it is formatted (+234 803…, 0803…).
     */
    public static function nationalDigits(string $e164): string
    {
        $d = preg_replace('/\D+/', '', $e164);

        return strlen($d) > 9 ? substr($d, -9) : $d;
    }

    public static function looksValid(?string $e164): bool
    {
        return $e164 !== null && (bool) preg_match('/^\+\d{8,15}$/', $e164);
    }
}
