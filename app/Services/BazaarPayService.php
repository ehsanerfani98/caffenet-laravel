<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BazaarPay (بازارپی) payment service — server-side/web gateway of Cafe Bazaar.
 *
 * Docs: https://github.com/BazaarPay/Docs/tree/main/fa
 * Base: https://api.bazaar-pay.ir/badje/v1 (direct-pay uses /badje/v2 for the
 * richer error body). Auth header: `Authorization: Token {merchant_token}`.
 *
 * Two flows are implemented:
 *
 *  1) Checkout (payment.md) — wallet top-up:
 *     init-checkout → redirect user to payment_url (+redirect_url, phone) →
 *     on return `trace` decides → `commit` finalises → fulfilment credits the
 *     wallet. Amounts travel in RIAL on the wire; the app stores Toman, so
 *     every amount is converted through `bazaarPayRialRate` (default ×10).
 *
 *  2) Direct Pay (direct-pay.md) — automatic order payment through a signed
 *     contract: contract/init → user signs on app.bazaar-pay.ir →
 *     contract/trace = active → init-checkout + POST /direct-pay → commit.
 *
 *  Test mode (`paymentTestMode`) simulates every endpoint locally so the whole
 *  pipeline (including the internal /test-gateway page for the checkout flow)
 *  can be exercised without merchant credentials.
 *
 *  Idempotency: trace+commit are the source of truth — a commit that fails
 *  with a transient error is retried by the pollable status endpoint, and a
 *  checkout already committed (paid_committed) is treated as success.
 */
class BazaarPayService
{
    private const TIMEOUT = 20;

    /** BazaarPay trace statuses (payment.md#trace). */
    public const T_INVALID_TOKEN = 'invalid_token';

    public const T_UNPAID = 'unpaid';

    public const T_PAID_NOT_COMMITTED = 'paid_not_committed';

    public const T_PAID_NOT_COMMITTED_REFUNDED = 'paid_not_committed_refunded';

    public const T_PAID_COMMITTED = 'paid_committed';

    public const T_REFUNDED = 'refunded';

    public const T_TIMED_OUT = 'timed_out';

    /** Trace statuses that mean the money will never arrive. */
    public const FAILED_STATUSES = [
        self::T_INVALID_TOKEN,
        self::T_UNPAID,
        self::T_PAID_NOT_COMMITTED_REFUNDED,
        self::T_REFUNDED,
        self::T_TIMED_OUT,
    ];

    /** Direct-pay contract states (direct-pay.md#trace-contract). */
    public const C_NEW = 'new';

    public const C_ACTIVE = 'active';

    public const C_DECLINED = 'declined';

    public const C_CANCELLED = 'cancelled';

    public const C_EXPIRED = 'expired';

    // ────────────────────────────────────────────────────────── checkout ──

    /**
     * Create a checkout token (payment.md#init-checkout).
     *
     * @param  int  $amountToman  amount in Toman (converted to Rial on the wire)
     * @param  string  $serviceName  what the user is paying for (max 512 chars)
     * @return array{ok: bool, checkoutToken?: string, paymentUrl?: string, error?: string}
     */
    public static function initCheckout(int $amountToman, string $serviceName): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if (! $cfg['testMode']) {
            if (! $cfg['enabled']) {
                return ['ok' => false, 'error' => 'درگاه بازارپی فعال نیست.'];
            }
            if ($cfg['token'] === '') {
                return ['ok' => false, 'error' => 'توکن مرچنت بازارپی تنظیم نشده است.'];
            }
            if ($cfg['destination'] === '') {
                return ['ok' => false, 'error' => 'نام مقصد (Destination) بازارپی تنظیم نشده است.'];
            }
            // redirect_url travels to the gateway and the browser is bounced
            // back to it, so it must be absolute.
            if (! str_starts_with($cfg['callbackUrl'], 'http://') && ! str_starts_with($cfg['callbackUrl'], 'https://')) {
                return ['ok' => false, 'error' => '«نشانی پایه سایت» در تنظیمات سیستمی وارد نشده است؛ نشانی بازگشت درگاه باید مطلق باشد.'];
            }
        }

        $amountRial = self::toRial($amountToman, $cfg);
        if ($amountRial < 10000) {
            return ['ok' => false, 'error' => 'حداقل مبلغ تراکنش در بازارپی ۱٬۰۰۰ تومان است.'];
        }

        if ($cfg['testMode']) {
            $token = 'TEST-'.Str::upper(Str::random(24));

            return [
                'ok' => true,
                'checkoutToken' => $token,
                'paymentUrl' => '/test-gateway?authority='.urlencode($token),
            ];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v1/checkout/init/', [
                    'amount' => $amountRial,
                    'destination' => $cfg['destination'],
                    'service_name' => Str::limit($serviceName, 512, ''),
                ]);

            $json = $response->json() ?? [];

            // success: {checkout_token, payment_url}
            if ($response->successful() && ! empty($json['checkout_token'])) {
                return [
                    'ok' => true,
                    'checkoutToken' => (string) $json['checkout_token'],
                    'paymentUrl' => (string) ($json['payment_url'] ?? ''),
                ];
            }

            Log::warning('BazaarPay init-checkout rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'ایجاد تراکنش پرداخت بازارپی ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay init-checkout failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در ارتباط با درگاه بازارپی.'];
        }
    }

    /**
     * Build the gateway URL the user must be redirected to: the payment_url
     * returned by init-checkout plus redirect_url (always absolute, with the
     * checkout token already embedded so the callback never depends on what
     * BazaarPay appends) and the buyer's phone for a faster login.
     */
    public static function gatewayRedirectUrl(string $paymentUrl, string $checkoutToken, string $phone): string
    {
        $cfg = SettingsService::bazaarPaySettings();
        $separator = str_contains($paymentUrl, '?') ? '&' : '?';

        $params = [
            'redirect_url' => $cfg['callbackUrl'].'/verify?authority='.urlencode($checkoutToken).'&gw=bazaarpay',
        ];
        if (trim($phone) !== '') {
            $params['phone'] = trim($phone);
        }

        return $paymentUrl.$separator.http_build_query($params);
    }

    /**
     * Trace a checkout token (payment.md#trace). Works without auth.
     *
     * @return array{ok: bool, status?: string, error?: string}
     */
    public static function trace(string $checkoutToken): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($checkoutToken, 'TEST-')) {
            return ['ok' => true, 'status' => self::T_PAID_NOT_COMMITTED];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($cfg['baseUrl'].'/badje/v1/trace/', [
                    'checkout_token' => $checkoutToken,
                ]);

            $json = $response->json() ?? [];
            $status = (string) ($json['status'] ?? '');

            if ($response->successful() && $status !== '') {
                return ['ok' => true, 'status' => $status];
            }

            Log::warning('BazaarPay trace rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'بررسی وضعیت پرداخت بازارپی ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay trace failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در بررسی وضعیت پرداخت بازارپی.'];
        }
    }

    /**
     * Commit a checkout (payment.md#commit) — confirms service delivery so the
     * money is not auto-returned to the user's Bazaar wallet. 204 = committed.
     *
     * @return array{ok: bool, already?: bool, error?: string}
     */
    public static function commit(string $checkoutToken): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($checkoutToken, 'TEST-')) {
            return ['ok' => true];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v1/commit/', [
                    'checkout_token' => $checkoutToken,
                ]);

            // 204 No Content = committed successfully.
            if ($response->status() === 204) {
                return ['ok' => true];
            }

            $json = $response->json() ?? [];

            // Already committed → idempotent success (double callback safety).
            $detail = (string) ($json['detail'] ?? '');
            if ($response->status() === 400 && str_contains($detail, 'commit')) {
                // Ambiguous body — verify through trace before claiming success.
                $traced = self::trace($checkoutToken);
                if ($traced['ok'] && $traced['status'] === self::T_PAID_COMMITTED) {
                    return ['ok' => true, 'already' => true];
                }
            }

            Log::warning('BazaarPay commit rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'تایید تراکنش بازارپی ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay commit failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در تایید تراکنش بازارپی.'];
        }
    }

    /**
     * Full/partial refund (payment.md#refund). Only idempotent per checkout
     * token: the FIRST successful call defines the refunded amount.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function refund(string $checkoutToken, ?int $amountToman = null): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($checkoutToken, 'TEST-')) {
            return ['ok' => true];
        }

        $payload = ['checkout_token' => $checkoutToken];
        if ($amountToman !== null) {
            $payload['amount'] = self::toRial($amountToman, $cfg);
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v1/refund/', $payload);

            if ($response->status() === 204) {
                return ['ok' => true];
            }

            Log::warning('BazaarPay refund rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($response->json() ?? [], 'عودت وجه بازارپی ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay refund failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در عودت وجه بازارپی.'];
        }
    }

    // ──────────────────────────────────────────────────────── direct pay ──

    /**
     * Create a direct-pay contract (direct-pay.md#init-contract).
     *
     * @param  string  $type  `wallet` (Bazaar wallet) or `direct_debit` (bank)
     * @param  string  $period  daily|weekly|monthly|quarterly|semiannual|yearly
     * @param  int  $amountLimitToman  per-period spending cap (min 10,000 Toman on the wire)
     * @return array{ok: bool, contractToken?: string, contractPageUrl?: string, error?: string}
     */
    public static function initContract(string $type, string $period, int $amountLimitToman): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode']) {
            return ['ok' => true, 'contractToken' => 'TEST-CT-'.Str::upper(Str::random(20))];
        }

        if ($cfg['token'] === '') {
            return ['ok' => false, 'error' => 'توکن مرچنت بازارپی تنظیم نشده است.'];
        }

        // The signing page redirects the user back to this URL — must be absolute.
        if (! str_starts_with($cfg['callbackUrl'], 'http://') && ! str_starts_with($cfg['callbackUrl'], 'https://')) {
            return ['ok' => false, 'error' => '«نشانی پایه سایت» در تنظیمات سیستمی وارد نشده است؛ نشانی بازگشت درگاه باید مطلق باشد.'];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v1/direct-pay/contract/init/', [
                    'type' => $type,
                    'period' => $period,
                    'amount_limit' => max(100000, self::toRial($amountLimitToman, $cfg)),
                    // The user is redirected back with the contract token in the
                    // URL, so the callback never depends on extra query params.
                    'redirect_url' => $cfg['callbackUrl'].('/bazaarpay/contract'),
                ]);

            $json = $response->json() ?? [];

            if ($response->successful() && ! empty($json['contract_token'])) {
                return ['ok' => true, 'contractToken' => (string) $json['contract_token']];
            }

            Log::warning('BazaarPay contract/init rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'ایجاد قرارداد پرداخت مستقیم ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay contract/init failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در ارتباط با درگاه بازارپی.'];
        }
    }

    /** User-facing signing page for a direct-pay contract (direct-pay.md#verify-contract). */
    public static function contractPageUrl(string $contractToken, string $phone, string $message = ''): string
    {
        $params = [
            'contract_token' => $contractToken,
            'redirect_url' => SettingsService::bazaarPaySettings()['callbackUrl'].'/bazaarpay/contract',
        ];
        if (trim($phone) !== '') {
            $params['phone'] = trim($phone);
        }
        if (trim($message) !== '') {
            $params['message'] = trim($message);
        }

        return 'https://app.bazaar-pay.ir/contract/direct-pay?'.http_build_query($params);
    }

    /**
     * Trace a contract (direct-pay.md#trace-contract).
     *
     * @return array{ok: bool, state?: string, amountLimit?: int, limitRemaining?: int, expirationTime?: ?string, limitExpirationTime?: ?string, error?: string}
     */
    public static function traceContract(string $contractToken): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($contractToken, 'TEST-')) {
            return ['ok' => true, 'state' => self::C_ACTIVE];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->get($cfg['baseUrl'].'/badje/v1/direct-pay/contract/trace/', [
                    'contract_token' => $contractToken,
                ]);

            $json = $response->json() ?? [];

            if ($response->successful() && isset($json['state'])) {
                return [
                    'ok' => true,
                    'state' => (string) $json['state'],
                    'amountLimit' => isset($json['amount_limit']) ? self::toToman((int) $json['amount_limit'], $cfg) : null,
                    'limitRemaining' => isset($json['limit_remaining_amount']) ? self::toToman((int) $json['limit_remaining_amount'], $cfg) : null,
                    'expirationTime' => $json['expiration_time'] ?? null,
                    'limitExpirationTime' => $json['limit_expiration_time'] ?? null,
                ];
            }

            Log::warning('BazaarPay contract/trace rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'بررسی وضعیت قرارداد ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay contract/trace failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در بررسی وضعیت قرارداد.'];
        }
    }

    /**
     * Pay with an ACTIVE contract (direct-pay.md#payment) — v2 endpoint for
     * the structured `{detail, code}` error body.
     *
     * @return array{ok: bool, error?: string, code?: string}
     */
    public static function payWithContract(string $contractToken, string $checkoutToken): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($contractToken, 'TEST-')) {
            return ['ok' => true];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v2/direct-pay/', [
                    'contract_token' => $contractToken,
                    'checkout_token' => $checkoutToken,
                ]);

            // 204 No Content = paid.
            if ($response->status() === 204) {
                return ['ok' => true];
            }

            $json = $response->json() ?? [];

            Log::warning('BazaarPay direct-pay rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $detail = (string) ($json['detail'] ?? '');
            $message = $detail !== '' ? $detail : 'پرداخت مستقیم بازارپی ناموفق بود.';

            return [
                'ok' => false,
                'error' => $detail !== '' ? $detail : self::errorMessage($json, $message),
                'code' => (string) ($json['code'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay direct-pay failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در پرداخت مستقیم بازارپی.'];
        }
    }

    /**
     * Remaining payable amount + wallet balance for a wallet-type contract
     * (direct-pay.md#get-balance).
     *
     * @return array{ok: bool, payableAmount?: int, userBalance?: int, error?: string}
     */
    public static function payableAmount(string $contractToken): array
    {
        $cfg = SettingsService::bazaarPaySettings();

        if ($cfg['testMode'] || str_starts_with($contractToken, 'TEST-')) {
            return ['ok' => true, 'payableAmount' => 0, 'userBalance' => 0];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withToken($cfg['token'], 'Token')
                ->post($cfg['baseUrl'].'/badje/v1/direct-pay/contract/payable-amount/', [
                    'contract_token' => $contractToken,
                ]);

            $json = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'payableAmount' => isset($json['payable_amount']) ? self::toToman((int) $json['payable_amount'], $cfg) : null,
                    'userBalance' => isset($json['user_balance']) ? self::toToman((int) $json['user_balance'], $cfg) : null,
                ];
            }

            return ['ok' => false, 'error' => self::errorMessage($json, 'دریافت موجودی قرارداد ناموفق بود.')];
        } catch (\Throwable $e) {
            Log::warning('BazaarPay payable-amount failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در دریافت موجودی قرارداد.'];
        }
    }

    // ─────────────────────────────────────────────────────────── helpers ──

    /** Toman → Rial for the wire. */
    public static function toRial(int $toman, ?array $cfg = null): int
    {
        $cfg = $cfg ?? SettingsService::bazaarPaySettings();
        $rate = $cfg['rialRate'] > 0 ? (int) $cfg['rialRate'] : 10;

        return $toman * $rate;
    }

    /** Rial (from BazaarPay responses) → Toman for local storage. */
    public static function toToman(int $rial, ?array $cfg = null): int
    {
        $cfg = $cfg ?? SettingsService::bazaarPaySettings();
        $rate = $cfg['rialRate'] > 0 ? (int) $cfg['rialRate'] : 10;

        return intdiv($rial, $rate);
    }

    /** Persian error message from the {detail} / {detail, code} / field shapes. */
    private static function errorMessage(array $json, string $fallback): string
    {
        $detail = $json['detail'] ?? null;
        if (is_string($detail) && trim($detail) !== '') {
            return trim($detail);
        }
        if (is_array($detail) && isset($detail[0]) && is_string($detail[0])) {
            return trim($detail[0]);
        }
        // Field errors: {field: ["msg", …]}
        foreach ($json as $value) {
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return trim($value[0]);
            }
        }

        return $fallback;
    }
}
