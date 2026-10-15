<?php

namespace App\Services;

use App\Models\SmsPatternVar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ippanel SMS service — ALL messages are pattern-based via the Edge API.
 *
 * Architecture:
 *   1. SmsTypeRegistry (code) defines what internal data each SMS type needs.
 *   2. sms_pattern_vars (DB) stores the admin's mapping:
 *        var_name (IPPanel) → data_field (internal identifier)
 *   3. The caller provides a data pool with internal identifiers as keys.
 *   4. resolveParams() reads the mapping, looks up each data_field in the
 *      pool, and sends the value under the admin's custom var_name.
 *
 * No IPPanel variable names are hardcoded anywhere in the code.
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
     * Send the OTP SMS.
     * The registry says OTP needs: ['code'].
     * The admin maps their IPPanel var name → 'code'.
     */
    public static function sendOtp(string $phone, string $code): void
    {
        self::send(
            phone: $phone,
            type: 'otp',
            data: ['code' => $code],
            logText: 'کد تایید ورود'
        );
    }

    /**
     * Send an order-status notification SMS.
     * The caller provides a data pool with internal identifiers as keys.
     * The registry defines which identifiers each type needs.
     */
    public static function sendOrderNotification(string $phone, string $type, array $data = []): void
    {
        if (! SettingsService::bool('smsNotifyEnabled')) {
            return;
        }

        self::send(
            phone: $phone,
            type: $type,
            data: $data,
            logText: 'پیامک '.$type
        );
    }

    /**
     * Core: send a pattern SMS via the Edge API.
     *
     * Steps:
     *   1. Read the pattern code from settings (via the registry's setting_key).
     *   2. Read the admin's variable mappings from sms_pattern_vars.
     *   3. For each mapping, look up data_field in the $data pool.
     *   4. Build params: {admin_var_name: value_from_data_pool}
     *   5. Validate that all required data is present.
     *   6. POST to the Edge API.
     */
    public static function send(string $phone, string $type, array $data, string $logText): void
    {
        try {
            $s = SettingsService::smsSettings();

            // 1. Check if SMS is enabled
            if (! $s['enabled']) {
                self::recordLog($phone, $logText.' — ارسال نشد (پیامک غیرفعال)', $type, 'failed', 'sms disabled');
                return;
            }

            // 2. Get the pattern code from settings
            $settingKey = SmsTypeRegistry::settingKey($type);
            if (! $settingKey) {
                self::recordLog($phone, $logText.' — نوع پیامک نامعتبر', $type, 'failed', 'unknown type: '.$type);
                return;
            }
            $patternCode = (string) SettingsService::raw($settingKey);

            // 3. Build params from the admin's variable mappings
            $params = self::buildParams($type, $data);

            // 4. Test mode — log and return
            if ($s['testMode']) {
                $desc = $logText.' (آزمایشی) — pattern: '.$patternCode.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE);
                self::recordLog($phone, $desc, $type, 'test');
                return;
            }

            // 5. Validate
            if (! $s['canSend']) {
                self::recordLog($phone, $logText.' — ارسال نشد (اعتبارنامه ناقص)', $type, 'failed', 'missing credentials');
                return;
            }
            if ($patternCode === '') {
                self::recordLog($phone, $logText.' — ارسال نشد (کد پترن خالی)', $type, 'failed', 'empty pattern code');
                return;
            }

            // 6. Send to Edge API
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
     * Build the params object for the Edge API.
     *
     * Reads the admin's variable mappings from sms_pattern_vars for this
     * SMS type. Each row maps:
     *   var_name   = the name the admin used in IPPanel (e.g. "otp", "factor")
     *   data_field = the internal identifier (e.g. "code", "order_number")
     *
     * The system looks up data_field in $data to get the value, then sends
     * it to IPPanel under the admin's custom var_name.
     *
     * Example:
     *   DB rows: var_name="otp", data_field="code"
     *   data: {"code": "12345"}
     *   result: {"otp": "12345"}
     *
     * Validation:
     *   - If a mapping's data_field is not in $data, logs a warning and
     *     sends empty string for that var.
     *   - Duplicate var_names are skipped (last one wins).
     */
    private static function buildParams(string $type, array $data): array
    {
        $rows = SmsPatternVar::where('pattern_key', $type)
            ->orderBy('sort_order')
            ->get();

        $params = [];
        $seen = [];
        foreach ($rows as $row) {
            // Skip duplicates
            if (in_array($row->var_name, $seen, true)) {
                continue;
            }
            $seen[] = $row->var_name;

            // Look up the internal identifier in the data pool
            $value = $data[$row->data_field] ?? '';
            if ($value === '' && ! isset($data[$row->data_field])) {
                Log::warning('SmsService: data_field not found in data pool', [
                    'type' => $type,
                    'var_name' => $row->var_name,
                    'data_field' => $row->data_field,
                ]);
            }
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
