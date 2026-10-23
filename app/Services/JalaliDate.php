<?php

namespace App\Services;

/**
 * Formats timestamps for customer-facing SMS messages using the Jalali
 * (Shamsi) calendar with Persian digits — the format Iranian customers
 * expect, e.g. «۱۴۰۵/۸/۱ ۱۵:۳۳».
 *
 * The whole app stores UTC timestamps (config/app.php timezone=UTC), but
 * customers live in Iran, so every value is converted to Asia/Tehran
 * before formatting.
 *
 * The Gregorian→Jalali conversion uses the standard public-domain
 * algorithm (the same one used by jdf.scr.ir) so we don't depend on the
 * intl extension (not installed in this runtime) or any composer package.
 *
 * This helper must NEVER throw — SMS content formatting must not break
 * the order lifecycle. On any parse failure the original value is
 * returned untouched.
 */
class JalaliDate
{
    /** Customer-facing display timezone (Iran, fixed +03:30 since 2022). */
    public const DISPLAY_TIMEZONE = 'Asia/Tehran';

    private const PERSIAN_DIGITS = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    /**
     * Format a timestamp as «YYYY/M/D HH:MM» in the Jalali calendar with
     * Persian digits (e.g. ۱۴۰۵/۸/۱ ۱۵:۳۳).
     *
     * Accepts DateTimeInterface instances, ISO-8601 strings (with or
     * without offset — offset-less strings are assumed UTC, matching the
     * app timezone) or null/empty. Empty values yield an empty string so
     * an unset variable renders as nothing in the SMS, as before.
     */
    public static function format($value, ?string $timezone = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $tz = new \DateTimeZone($timezone ?? self::DISPLAY_TIMEZONE);

            if ($value instanceof \DateTimeInterface) {
                $dt = \DateTimeImmutable::createFromFormat(
                    'U',
                    (string) $value->getTimestamp()
                )->setTimezone($tz);
            } else {
                $raw = trim((string) $value);
                // Offset-less strings are assumed UTC (the app timezone).
                if (! preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $raw)) {
                    $raw .= '+00:00';
                }
                $dt = new \DateTimeImmutable($raw);
                $dt = $dt->setTimezone($tz);
            }
        } catch (\Throwable $e) {
            // Unparseable value — return it as-is rather than breaking
            // the SMS (pattern send failures are already non-fatal).
            return (string) $value;
        }

        [$jy, $jm, $jd] = self::toJalali(
            (int) $dt->format('Y'),
            (int) $dt->format('n'),
            (int) $dt->format('j')
        );

        // H:i keeps a leading zero on the hour (۱۶:۳۰); month/day are
        // not zero-padded, matching the format the customer expects.
        return self::digits($jy . '/' . $jm . '/' . $jd . ' ' . $dt->format('H:i'));
    }

    /**
     * Convert Latin digits to Persian digits (۰-۹).
     */
    public static function digits(string $text): string
    {
        return strtr($text, self::PERSIAN_DIGITS);
    }

    /**
     * Convert a Gregorian date to the Jalali (Shamsi) calendar.
     *
     * Standard public-domain algorithm (jdf.scr.ir variant). Returns
     * [year, month, day] as integers.
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666
            + 365 * $gy
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + $gdm[$gm - 1];

        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}
