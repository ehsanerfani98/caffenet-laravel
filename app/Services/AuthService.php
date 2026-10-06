<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * OTP dispatch/verification — the OTP layer feeding Laravel Fortify's
 * authentication pipeline (see AppServiceProvider::authenticateUsing).
 *
 * Fortify owns sessions, throttling and login events; this service only
 * generates, stores and verifies the 5-digit codes.
 *
 * Preserved guarantees:
 *  - 5-digit OTP, 2-minute TTL, max 5 verification attempts per code.
 *  - OTP stored hashed with HMAC-SHA256 keyed by the application key over
 *    `${phone}:${code}`; rotating APP_KEY invalidates pending codes (and
 *    sessions, which Laravel already ties to APP_KEY).
 *  - Max 3 new codes per phone per 10 minutes (independent of IP limits).
 *  - timing-safe comparison via hash_equals.
 */
class AuthService
{
    public const OTP_TTL_SECONDS = 120;

    public const OTP_MAX_ATTEMPTS = 5;

    public const OTP_PER_PHONE_WINDOW = 10; // minutes

    public const OTP_PER_PHONE_MAX = 3;

    public static function generateOtp(): string
    {
        return (string) random_int(10000, 99999);
    }

    public static function hashOtp(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.':'.$code, (string) config('app.key'));
    }

    /** Persists a new hashed OTP; consumes previous unused codes of the phone. */
    public static function storeOtp(string $phone, string $code, string $ip): void
    {
        DB::table('otp_codes')->where('phone', $phone)->where('consumed', 0)->update(['consumed' => 1]);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => self::hashOtp($phone, $code),
            'request_ip' => $ip,
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS)->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /** Rate: max 3 new codes per phone per 10 minutes. */
    public static function otpQuotaExhausted(string $phone): bool
    {
        $since = now()->subMinutes(self::OTP_PER_PHONE_WINDOW)->toIso8601String();

        return DB::table('otp_codes')
            ->where('phone', $phone)
            ->where('created_at', '>=', $since)
            ->count() >= self::OTP_PER_PHONE_MAX;
    }

    /** @return array{ok: bool, reason: string|null} */
    public static function verifyOtp(string $phone, string $code): array
    {
        $row = DB::table('otp_codes')
            ->where('phone', $phone)
            ->where('consumed', 0)
            ->orderByDesc('created_at')
            ->first();

        if (! $row) {
            return ['ok' => false, 'reason' => 'no_code'];
        }
        if ($row->attempts >= self::OTP_MAX_ATTEMPTS) {
            return ['ok' => false, 'reason' => 'too_many'];
        }
        if ($row->expires_at < now()->toIso8601String()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $expected = (string) $row->code_hash;
        $given = self::hashOtp($phone, $code);

        if (! hash_equals($expected, $given)) {
            DB::table('otp_codes')->where('id', $row->id)->increment('attempts');

            return ['ok' => false, 'reason' => 'mismatch'];
        }

        DB::table('otp_codes')->where('id', $row->id)->update(['consumed' => 1]);

        return ['ok' => true, 'reason' => null];
    }

    /** Find or create the user for a verified phone (admin auto-promotion). */
    public static function findOrCreateUser(string $phone): User
    {
        $phone = self::normalizePhone($phone);
        $adminPhones = SettingsService::adminPhones();

        $user = User::where('phone', $phone)->first();
        if ($user) {
            if (in_array($phone, $adminPhones, true) && $user->role !== 'admin') {
                $user->syncAccess('admin', null);
            }

            return $user;
        }

        $last4 = substr($phone, -4);
        $role = in_array($phone, $adminPhones, true) ? 'admin' : 'customer';

        $user = User::create([
            'phone' => $phone,
            'name' => 'کاربر '.$last4,
            'role' => $role,
            'wallet_balance' => 0,
            'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);
        $user->syncAccess($role, null);

        return $user;
    }

    /** Normalizes Iranian mobile numbers to 09xxxxxxxxx (or +… international). */
    public static function normalizePhone(string $raw): string
    {
        $s = preg_replace('/[^0-9+]/', '', self::toLatinDigits($raw)) ?? '';
        if (str_starts_with($s, '+')) {
            return '+'.$ltrim = ltrim(substr($s, 1), '0');
        }
        if (str_starts_with($s, '00')) {
            return '+'.ltrim(substr($s, 2), '0');
        }
        if (str_starts_with($s, '98') && strlen($s) >= 12) {
            return '0'.substr($s, 2);
        }
        if (str_starts_with($s, '9') && strlen($s) === 10) {
            return '0'.$s;
        }

        return $s;
    }

    public static function isValidIranianMobile(string $phone): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalizePhone($phone));
    }

    public static function toLatinDigits(string $s): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($ar, $en, str_replace($fa, $en, $s));
    }
}
