<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ippanel SMS service — a faithful port of server/sms.ts.
 *
 *  - Auth uses the custom `apikey` header (no Bearer/AccessKey prefix).
 *  - OTP via pattern: POST /sms/pattern/normal/send with a `code` variable.
 *  - Notifications via POST /sms/send/webservice/single.
 *  - Every send attempt is recorded in sms_logs (sent | failed | test).
 *  - Test mode never hits the provider and logs with status `test`.
 */
class SmsService
{
    private const TIMEOUT = 15;

    /** Logical gate: enabled && !testMode && apiKey && sender present. */
    public static function canSend(): bool
    {
        return SettingsService::smsSettings()['canSend'];
    }

    /** Send the OTP pattern message. Returns the test code when in test mode. */
    public static function sendOtp(string $phone, string $code): void
    {
        $s = SettingsService::smsSettings();

        if (! $s['enabled']) {
            self::recordLog($phone, 'کد تایید ارسال نشد (پیامک غیرفعال)', 'otp', 'failed', 'sms disabled');

            return;
        }

        if ($s['testMode']) {
            self::recordLog($phone, "کد تایید (آزمایشی): {$code}", 'otp', 'test');

            return;
        }

        if (! $s['canSend']) {
            self::recordLog($phone, 'کد تایید ارسال نشد (اعتبارنامه پیامک ناقص)', 'otp', 'failed', 'missing credentials');

            return;
        }

        $pattern = (string) SettingsService::raw('smsOtpPattern');
        $base = rtrim((string) SettingsService::raw('smsBaseUrl'), '/');

        try {
            $response = Http::withTimeout(self::TIMEOUT)
                ->withHeaders(['apikey' => $s['apiKey']])
                ->post($base.'/sms/pattern/normal/send', [
                    'code' => $code,
                    'sender' => self::normalizePhone($s['sender']),
                    'receptor' => self::normalizePhone($phone),
                    'pattern' => $pattern,
                ]);

            if ($response->successful()) {
                self::recordLog($phone, 'کد تایید ورود ارسال شد', 'otp', 'sent', null, $response->json('data.message_id') ?? null);
            } else {
                self::recordLog($phone, 'ارسال کد تایید ناموفق بود', 'otp', 'failed', 'HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            self::recordLog($phone, 'ارسال کد تایید ناموفق بود', 'otp', 'failed', mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** Best-effort notification SMS (never throws). */
    public static function sendNotification(string $phone, string $text, string $type = 'order_status'): void
    {
        try {
            $s = SettingsService::smsSettings();

            if (! $s['enabled'] || ! $s['canSend']) {
                if ($s['testMode'] && $s['enabled']) {
                    self::recordLog($phone, $text, $type, 'test');
                }

                return;
            }

            $base = rtrim((string) SettingsService::raw('smsBaseUrl'), '/');

            $response = Http::withTimeout(self::TIMEOUT)
                ->withHeaders(['apikey' => $s['apiKey']])
                ->post($base.'/sms/send/webservice/single', [
                    'recipient' => [self::normalizePhone($phone)],
                    'sender' => self::normalizePhone($s['sender']),
                    'message' => $text,
                ]);

            if ($response->successful()) {
                self::recordLog($phone, $text, $type, 'sent');
            } else {
                self::recordLog($phone, $text, $type, 'failed', 'HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            self::recordLog($phone, $text, $type, 'failed', mb_substr($e->getMessage(), 0, 200));
        }
    }

    public static function recordLog(string $phone, string $text, string $type, string $status, ?string $error = null, ?string $providerId = null): void
    {
        \App\Models\SmsLog::create([
            'phone' => $phone,
            'text' => $text,
            'type' => $type,
            'status' => $status,
            'provider_message_id' => $providerId,
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /** Normalize to international 98… format used by ippanel. */
    public static function normalizePhone(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) {
            return '98'.substr($p, 3);
        }
        if (str_starts_with($p, '0') && strlen($p) === 11) {
            return '98'.substr($p, 1);
        }

        return $p;
    }
}
