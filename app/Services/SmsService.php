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

    /**
     * Send a pattern-based SMS via the ippanel Edge API.
     *
     * Endpoint: POST https://edge.ippanel.com/v1/api/send
     * Body: { sending_type: "pattern", from_number, code, recipients: [..], params: {..} }
     * Auth:  Authorization header with the apikey.
     *
     * @param  string  $phone   Recipient phone (normalized to +98…).
     * @param  string  $patternCode  The pattern code from the ippanel panel.
     * @param  array   $params  Pattern variables: {var_name => value, …}.
     * @param  string  $type    sms_logs.type value (order_started | order_ready | …).
     * @param  string  $logText A short description for the sms_logs row.
     * @return bool True on success.
     */
    public static function sendPattern(string $phone, string $patternCode, array $params = [], string $type = 'order_status', string $logText = ''): bool
    {
        try {
            $s = SettingsService::smsSettings();

            if (! $s['enabled']) {
                self::recordLog($phone, $logText ?: 'پیامک پترن ارسال نشد (پیامک غیرفعال)', $type, 'failed', 'sms disabled');
                return false;
            }

            if ($s['testMode']) {
                $desc = $logText ?: 'پیامک پترن (آزمایشی)';
                $desc .= ' — pattern: '.$patternCode.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE);
                self::recordLog($phone, $desc, $type, 'test');
                return true;
            }

            if (! $s['canSend']) {
                self::recordLog($phone, $logText ?: 'پیامک پترن ارسال نشد (اعتبارنامه ناقص)', $type, 'failed', 'missing credentials');
                return false;
            }

            if ($patternCode === '') {
                self::recordLog($phone, $logText ?: 'پیامک پترن ارسال نشد (کد پترن خالی)', $type, 'failed', 'empty pattern code');
                return false;
            }

            // Build the Edge API request body.
            // from_number must be in E.164 (+98…) format for the Edge API.
            $fromNumber = self::toE164($s['sender']);
            $recipient = self::toE164($phone);

            $body = [
                'sending_type' => 'pattern',
                'from_number' => $fromNumber,
                'code' => $patternCode,
                'recipients' => [$recipient],
                'params' => $params,
            ];

            $response = Http::withTimeout(self::TIMEOUT)
                ->withHeaders([
                    'Authorization' => $s['apiKey'],
                    'Content-Type' => 'application/json',
                ])
                ->post('https://edge.ippanel.com/v1/api/send', $body);

            $respJson = $response->json();
            $ok = $response->successful() && (bool) ($respJson['meta']['status'] ?? false);
            $providerId = isset($respJson['data']['message_outbox_ids'][0])
                ? (string) $respJson['data']['message_outbox_ids'][0]
                : null;

            if ($ok) {
                self::recordLog($phone, $logText ?: 'پیامک پترن ارسال شد', $type, 'sent', null, $providerId);
                return true;
            }

            $err = 'HTTP '.$response->status();
            if (isset($respJson['meta']['message'])) {
                $err .= ' — '.$respJson['meta']['message'];
            }
            self::recordLog($phone, $logText ?: 'پیامک پترن ناموفق', $type, 'failed', $err, $providerId);
            return false;
        } catch (\Throwable $e) {
            self::recordLog($phone, $logText ?: 'پیامک پترن ناموفق', $type, 'failed', mb_substr($e->getMessage(), 0, 200));
            return false;
        }
    }

    /**
     * Send an order-status notification SMS — uses the pattern configured
     * for the given event type when one is set; falls back to a plain-text
     * SMS via sendNotification() otherwise.
     *
     * @param  string  $phone       Customer phone.
     * @param  string  $eventType   One of: order_started, order_ready,
     *                              requeued, cancelled, reminder, broadcast.
     * @param  array   $vars        Pattern variables to substitute
     *                              (e.g. {order_number, customer_name, eta}).
     * @param  string  $fallbackText  Plain-text message used when no pattern
     *                              is configured for this event type.
     */
    public static function sendOrderNotification(string $phone, string $eventType, array $vars = [], string $fallbackText = ''): void
    {
        if (! SettingsService::bool('smsNotifyEnabled')) {
            return;
        }

        // Map event type → settings key holding the pattern code.
        $patternKey = [
            'order_started' => 'smsPatternStarted',
            'order_ready'   => 'smsPatternReady',
            'requeued'      => 'smsPatternRequeued',
            'cancelled'     => 'smsPatternCancelled',
            'reminder'      => 'smsPatternReminder',
            'broadcast'     => 'smsPatternBroadcast',
        ][$eventType] ?? null;

        $patternCode = $patternKey ? (string) SettingsService::raw($patternKey) : '';

        if ($patternCode !== '') {
            self::sendPattern($phone, $patternCode, $vars, $eventType, $fallbackText);
        } elseif ($fallbackText !== '') {
            self::sendNotification($phone, $fallbackText, $eventType);
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

    /**
     * Normalize to E.164 (+98…) format used by the ippanel Edge API.
     * The Edge API expects numbers like +989120000000, NOT 989120000000.
     */
    public static function toE164(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) {
            return $p;
        }
        if (str_starts_with($p, '0') && strlen($p) === 11) {
            return '+98'.substr($p, 1);
        }
        if (str_starts_with($p, '98')) {
            return '+'.$p;
        }

        return $p;
    }
}
