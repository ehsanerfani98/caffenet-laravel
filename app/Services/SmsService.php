<?php

namespace App\Services;

use App\Models\SmsPatternVar;
use Illuminate\Support\Facades\Http;

/**
 * ippanel SMS service — ALL messages are pattern-based via the Edge API.
 *
 * The admin creates patterns in ippanel with their OWN variable names
 * (e.g. %customer%, %order%). For each pattern event, the admin enters
 * in the settings panel:
 *   1. The pattern code (from ippanel)
 *   2. A list of variable mappings — each row maps the admin's custom
 *      variable name to a system data field:
 *        var_name="customer" → data_field="customer_name"
 *        var_name="order"    → data_field="order_number"
 *
 * These mappings are stored in the sms_pattern_vars table.
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
     * All system data fields that can be mapped to pattern variables.
     * The admin picks from this list when configuring each variable.
     */
    public const DATA_FIELDS = [
        'order_number'       => 'شماره سفارش (CN-1234)',
        'customer_name'      => 'نام مشتری',
        'customer_phone'     => 'شماره موبایل مشتری',
        'service_title'      => 'عنوان خدمت',
        'status'             => 'کد وضعیت سفارش',
        'status_title'       => 'عنوان فارسی وضعیت',
        'eta_minutes'        => 'زمان انتظار تقریبی (دقیقه)',
        'duration_minutes'   => 'مدت انجام تقریبی (دقیقه)',
        'start_time'         => 'زمان تقریبی شروع',
        'end_time'           => 'زمان تقریبی تکمیل',
        'queue_position'     => 'موقعیت در صف',
        'tracking_url'       => 'لینک پیگیری عمومی',
        'amount'             => 'مبلغ (تومان)',
        'payment_method'     => 'روش پرداخت',
        'minutes_before'     => 'دقیقه تا شروع (یادآوری)',
        'code'               => 'کد تایید OTP',
        'title'              => 'عنوان (همگانی)',
        'body'               => 'متن (همگانی)',
        'site_title'         => 'عنوان سایت',
    ];

    /**
     * Send the OTP pattern message.
     */
    public static function sendOtp(string $phone, string $code): void
    {
        self::sendPattern(
            phone: $phone,
            patternKey: 'otp',
            patternCode: (string) SettingsService::raw('smsPatternOtp'),
            data: ['code' => $code],
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
     * Core: send a pattern SMS.
     *
     * Reads the admin's variable mappings from sms_pattern_vars for this
     * patternKey, resolves each variable from the $data pool, and POSTs
     * to the Edge API.
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

            // Build params from the admin's variable mappings.
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
     * Read the admin's variable mappings from sms_pattern_vars and resolve
     * each variable from the data pool.
     *
     * Example: patternKey = "order_started", rows in DB:
     *   var_name="customer" → data_field="customer_name"
     *   var_name="order"    → data_field="order_number"
     *
     * With data = {"customer_name": "احسان", "order_number": "CN-1234", ...}
     * Result: {"customer": "احسان", "order": "CN-1234"}
     */
    private static function resolveParams(string $patternKey, array $data): array
    {
        $rows = SmsPatternVar::where('pattern_key', $patternKey)
            ->orderBy('sort_order')
            ->get();

        $params = [];
        foreach ($rows as $row) {
            $value = $data[$row->data_field] ?? '';
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
