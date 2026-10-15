<?php

namespace App\Services;

use App\Models\SmsPatternVar;
use Illuminate\Support\Facades\Http;

/**
 * ippanel SMS service — ALL messages are pattern-based via the Edge API.
 *
 * The admin workflow:
 *   1. Create a pattern in ippanel (e.g. "سفارش {order_number} آماده شد")
 *   2. Get the pattern code
 *   3. In the admin panel, enter: pattern code + variable names
 *      (e.g. "order_number" — just the name, nothing else)
 *
 * The system internally knows what each variable name means and fills it
 * with the correct value from the order data. The mapping is HARDCODED
 * in the VALUE_MAP below — the admin never selects a "data field".
 */
class SmsService
{
    private const TIMEOUT = 15;
    private const DEFAULT_EDGE_BASE_URL = 'https://edge.ippanel.com/v1';

    private static function edgeApiUrl(): string
    {
        $base = rtrim((string) SettingsService::raw('smsBaseUrl'), '/');
        if ($base === '') {
            $base = self::DEFAULT_EDGE_BASE_URL;
        }
        return $base.'/api/send';
    }

    public static function canSend(): bool
    {
        return SettingsService::smsSettings()['canSend'];
    }

    /**
     * HARDCODED internal mapping: variable name → how to extract its value.
     *
     * The admin just types the variable name (e.g. "order_number") in the
     * settings panel. The system looks it up here to know what value to
     * send. If a variable isn't in this map, it's sent as empty string.
     *
     * These are the ONLY variable names the system knows about. The admin
     * must use these exact names when creating patterns in ippanel.
     */
    private const VALUE_MAP = [
        'order_number'       => 'order_number',
        'customer_name'      => 'customer_name',
        'customer_phone'     => 'customer_phone',
        'service_title'      => 'service_title',
        'status'             => 'status',
        'status_title'       => 'status_title',
        'eta_minutes'        => 'eta_minutes',
        'duration_minutes'   => 'duration_minutes',
        'start_time'         => 'start_time',
        'end_time'           => 'end_time',
        'queue_position'     => 'queue_position',
        'tracking_url'       => 'tracking_url',
        'amount'             => 'amount',
        'payment_method'     => 'payment_method',
        'minutes_before'     => 'minutes_before',
        'code'               => 'code',
        'title'              => 'title',
        'body'               => 'body',
        'site_title'         => 'site_title',
    ];

    /**
     * Send the OTP pattern message.
     *
     * The admin may have named the OTP variable anything in ippanel
     * (e.g. "verification-code", "code", "otp"). We pass the code under
     * multiple possible names so whatever the admin named it, it resolves.
     */
    public static function sendOtp(string $phone, string $code): void
    {
        // Read the admin's variable names from the DB for the 'otp' pattern.
        // The admin entered e.g. "verification-code" — we need to pass
        // the OTP code value under that exact key.
        $vars = SmsPatternVar::where('pattern_key', 'otp')->get();
        $data = [];
        foreach ($vars as $v) {
            // Every OTP variable gets the code value — ippanel will use
            // whichever one matches the pattern template.
            $data[$v->var_name] = $code;
        }
        // Also pass 'code' as a fallback in case the admin named it that.
        if (! isset($data['code'])) {
            $data['code'] = $code;
        }

        self::sendPattern(
            phone: $phone,
            patternKey: 'otp',
            patternCode: (string) SettingsService::raw('smsPatternOtp'),
            data: $data,
            type: 'otp',
            logText: 'کد تایید ورود'
        );
    }

    /**
     * Send an order-status notification SMS.
     */
    public static function sendOrderNotification(string $phone, string $eventType, array $data = []): void
    {
        if (! SettingsService::bool('smsNotifyEnabled')) {
            return;
        }

        $patternSettingKey = self::EVENT_PATTERN_SETTING[$eventType] ?? null;
        if (! $patternSettingKey) {
            return;
        }

        self::sendPattern(
            phone: $phone,
            patternKey: $eventType,
            patternCode: (string) SettingsService::raw($patternSettingKey),
            data: $data,
            type: $eventType,
            logText: 'پیامک '.$eventType
        );
    }

    private const EVENT_PATTERN_SETTING = [
        'order_started'  => 'smsPatternStarted',
        'order_ready'    => 'smsPatternReady',
        'requeued'       => 'smsPatternRequeued',
        'cancelled'      => 'smsPatternCancelled',
        'reminder'       => 'smsPatternReminder',
        'paid'           => 'smsPatternPaid',
        'status_change'  => 'smsPatternStatusChange',
        'refund'         => 'smsPatternRefund',
        'broadcast'      => 'smsPatternBroadcast',
    ];

    /**
     * Core: send a pattern SMS via the Edge API.
     *
     * Reads the admin's variable names from sms_pattern_vars, looks up
     * each one in the VALUE_MAP to get the value from $data, and POSTs.
     */
    public static function sendPattern(
        string $phone,
        string $patternKey,
        string $patternCode,
        array $data,
        string $type,
        string $logText
    ): void {
        try {
            $s = SettingsService::smsSettings();

            if (! $s['enabled']) {
                self::recordLog($phone, $logText.' — ارسال نشد (پیامک غیرفعال)', $type, 'failed', 'sms disabled');
                return;
            }

            // Build params: read variable names from DB, get values from $data.
            $params = self::resolveParams($patternKey, $data);

            if ($s['testMode']) {
                $desc = $logText.' (آزمایشی) — pattern: '.$patternCode.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE);
                self::recordLog($phone, $desc, $type, 'test');
                return;
            }

            if (! $s['canSend']) {
                self::recordLog($phone, $logText.' — ارسال نشد (اعتبارنامه ناقص)', $type, 'failed', 'missing credentials');
                return;
            }

            if ($patternCode === '') {
                self::recordLog($phone, $logText.' — ارسال نشد (کد پترن خالی)', $type, 'failed', 'empty pattern code');
                return;
            }

            $body = [
                'sending_type' => 'pattern',
                'from_number'  => self::toE164($s['sender']),
                'code'         => $patternCode,
                'recipients'   => [self::toE164($phone)],
                'params'       => $params,
            ];

            $response = Http::withTimeout(self::TIMEOUT)
                ->withHeaders([
                    'Authorization' => $s['apiKey'],
                    'Content-Type'  => 'application/json',
                ])
                ->post(self::edgeApiUrl(), $body);

            $respJson = $response->json();
            $ok = $response->successful() && (bool) ($respJson['meta']['status'] ?? false);
            $providerId = isset($respJson['data']['message_outbox_ids'][0])
                ? (string) $respJson['data']['message_outbox_ids'][0]
                : null;

            if ($ok) {
                self::recordLog($phone, $logText.' — ارسال شد', $type, 'sent', null, $providerId);
            } else {
                $err = 'HTTP '.$response->status();
                if (isset($respJson['meta']['message'])) {
                    $err .= ' — '.$respJson['meta']['message'];
                }
                self::recordLog($phone, $logText.' — ناموفق', $type, 'failed', $err, $providerId);
            }
        } catch (\Throwable $e) {
            self::recordLog($phone, $logText.' — ناموفق', $type, 'failed', mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Read the admin's variable names from sms_pattern_vars and resolve
     * each one to its value from the $data pool.
     *
     * The DB only stores the variable NAME (e.g. "order_number").
     * The system looks it up in $data to get the value (e.g. "CN-1234").
     *
     * Example: DB rows for pattern_key="order_ready":
     *   var_name = "order_number"
     *
     * With data = {"order_number": "CN-1234", "customer_name": "احسان", ...}
     * Result: {"order_number": "CN-1234"}
     */
    /**
     * Read the admin's variable names from sms_pattern_vars and build
     * the params object for the Edge API.
     *
     * For each variable the admin declared, look up its value in the
     * $data pool. The admin's var_name must match a key in $data.
     *
     * For OTP: the caller passes data with the OTP code under every
     * var_name the admin declared (see sendOtp).
     *
     * For order notifications: the caller passes the full order data
     * pool (order_number, customer_name, etc.). The admin should use
     * these same names when creating patterns in ippanel.
     */
    private static function resolveParams(string $patternKey, array $data): array
    {
        $rows = SmsPatternVar::where('pattern_key', $patternKey)
            ->orderBy('sort_order')
            ->get();

        $params = [];
        foreach ($rows as $row) {
            $value = $data[$row->var_name] ?? '';
            $params[$row->var_name] = is_scalar($value) ? (string) $value : '';
        }
        return $params;
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

    public static function normalizePhone(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) return '98'.substr($p, 3);
        if (str_starts_with($p, '0') && strlen($p) === 11) return '98'.substr($p, 1);
        return $p;
    }

    public static function toE164(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) return $p;
        if (str_starts_with($p, '0') && strlen($p) === 11) return '+98'.substr($p, 1);
        if (str_starts_with($p, '98')) return '+'.$p;
        return $p;
    }
}
