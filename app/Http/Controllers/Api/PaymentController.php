<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Services\PaymentFulfillmentService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Caffenet\BazaarIap\Facades\BazaarIap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payments — wallet top-up gateway only.
 *
 * Services are paid exclusively from the wallet (see
 * OrderController::payWithWallet); this endpoint therefore only accepts
 * `purpose: wallet` and rejects any order-purpose request so a direct
 * gateway payment for a service can never be created. Amounts are validated
 * against MIN/MAX bounds and never trusted from the client.
 *  - verify is idempotent (codes 100/101, transactional state transitions).
 */
class PaymentController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $body = (array) $request->json()->all();
        $now = now()->toIso8601String();

        // Services are paid from the wallet only. This endpoint exists solely
        // for wallet top-ups, so any other purpose is rejected outright.
        if (($body['purpose'] ?? 'wallet') !== 'wallet') {
            return response()->json([
                'error' => 'پرداخت سفارش فقط از طریق کیف پول انجام می‌شود؛ ابتدا کیف پول را شارژ کنید.',
                'code' => 'WALLET_ONLY',
            ], 400);
        }

        $amount = (int) ($body['amount'] ?? 0);
        if ($amount < PaymentService::MIN_WALLET_TOPUP || $amount > PaymentService::MAX_WALLET_TOPUP) {
            return response()->json(['error' => 'مبلغ شارژ باید بین ۱۰٬۰۰۰ تا ۵۰٬۰۰۰٬۰۰۰ تومان باشد.'], 400);
        }
        $description = 'شارژ کیف پول';

        // Gateway selection: `online` (Zarinpal, default) or `bazaar`
        // (Cafe Bazaar in-app purchase via deep link + server-side consume).
        $gatewayKind = (($body['gateway'] ?? 'online') === 'bazaar') ? 'bazaar' : 'online';

        if ($gatewayKind === 'bazaar') {
            return $this->requestBazaar($user, $amount, $now);
        }

        // Pre-generate the payment id so it can travel as metadata.order_id for
        // traceability in the Zarinpal panel. The row itself is only persisted
        // once the gateway has accepted the request (no stray pending rows).
        $paymentId = 'pay-'.Str::uuid();

        $gatewayResult = PaymentService::requestPayment(
            $amount,
            $description,
            SettingsService::paymentSettings()['callbackUrl'],
            $user->phone,
            $paymentId
        );

        if (! $gatewayResult['ok']) {
            return response()->json(['error' => $gatewayResult['error']], 400);
        }

        $payment = Payment::create([
            'id' => $paymentId,
            'order_id' => null,
            'user_id' => $user->id,
            'purpose' => 'wallet',
            'amount' => $amount,
            'authority' => $gatewayResult['authority'],
            'gateway' => str_starts_with($gatewayResult['authority'], 'TEST-') ? 'test' : 'zarinpal',
            'status' => 'pending',
            'created_at' => $now,
        ]);

        return response()->json([
            'paymentId' => $payment->id,
            'redirectUrl' => $gatewayResult['redirectUrl'],
            'authority' => $gatewayResult['authority'],
        ]);
    }

    /**
     * Gateway callback — verifies idempotently and advances the order or the
     * wallet inside one transaction. Never throws on double-callbacks.
     */
    public function verify(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $authority = (string) $request->query('Authority', $request->query('authority', ''));
        $status = (string) $request->query('Status', $request->query('status', ''));

        $appUrl = SettingsService::appUrl();
        $failUrl = ($appUrl ?: '').'/?payment=failed';
        $successUrl = ($appUrl ?: '').'/?payment=success&authority='.urlencode($authority);

        if ($authority === '') {
            return redirect()->to($failUrl);
        }

        $payment = Payment::where('authority', $authority)->first();
        if (! $payment) {
            return redirect()->to($failUrl);
        }

        // User-cancelled at gateway.
        if (strtoupper($status) !== 'OK') {
            if ($payment->status === 'pending') {
                DB::transaction(function () use ($payment) {
                    DB::table('payments')->where('id', $payment->id)->update(['status' => 'failed']);
                    if ($payment->order_id) {
                        $order = Order::find($payment->order_id);
                        if ($order && $order->status === Order::STATUS_PENDING_PAYMENT) {
                            DB::table('orders')->where('id', $order->id)
                                ->update(['status' => Order::STATUS_FAILED, 'updated_at' => now()->toIso8601String()]);
                            OrderLog::create([
                                'order_id' => $order->id,
                                'action' => 'پرداخت ناموفق بود',
                                'performed_by' => 'درگاه پرداخت',
                                'note' => 'بازگشت ناموفق از درگاه',
                                'timestamp' => now()->toIso8601String(),
                            ]);
                        }
                    }
                });
            }

            return redirect()->to($failUrl);
        }

        // Already handled previously → just redirect.
        if ($payment->status === 'paid') {
            return redirect()->to($successUrl);
        }

        $result = PaymentService::verifyPayment($authority, (int) $payment->amount);

        if (! $result['ok']) {
            DB::transaction(function () use ($payment) {
                DB::table('payments')->where('id', $payment->id)->update(['status' => 'failed']);
                if ($payment->order_id) {
                    $order = Order::find($payment->order_id);
                    if ($order && $order->status === Order::STATUS_PENDING_PAYMENT) {
                        DB::table('orders')->where('id', $order->id)
                            ->update(['status' => Order::STATUS_FAILED, 'updated_at' => now()->toIso8601String()]);
                        OrderLog::create([
                            'order_id' => $order->id,
                            'action' => 'پرداخت ناموفق بود',
                            'performed_by' => 'درگاه پرداخت',
                            'timestamp' => now()->toIso8601String(),
                        ]);
                    }
                }
            });

            return redirect()->to($failUrl);
        }

        // Verified — apply effects exactly once (shared, idempotent action).
        PaymentFulfillmentService::apply($payment, $result['refId'] ?? null, $result['cardPan'] ?? null);

        return redirect()->to($successUrl);
    }

    /** Pollable status used by the client after returning from the gateway. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $authority = (string) ($request->query('authority') ?? ($request->json('authority') ?? ''));

        $payment = Payment::where('authority', $authority)->where('user_id', $user->id)->first();
        if (! $payment) {
            return response()->json(['error' => 'تراکنش یافت نشد.'], 404);
        }

        return response()->json([
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'amount' => (int) $payment->amount,
            'refId' => $payment->ref_id,
            'orderId' => $payment->order_id,
        ]);
    }

    /**
     * Cafe Bazaar in-app gateway — wallet top-up only. Builds the deep link and
     * persists a pending payment whose `authority` is the visit id the
     * (package) callback resolves by. The actual consume + fulfilment happens
     * later, server-side, when Bazaar redirects the user back to the callback
     * route, which credits the wallet.
     */
    private function requestBazaar($user, int $amount, string $now): JsonResponse
    {
        if (! BazaarIap::isEnabled()) {
            return response()->json(['error' => 'درگاه پرداخت درون‌برنامه‌ای کافه‌بازار فعال نیست.'], 400);
        }

        // Cafe Bazaar charges the FIXED price of a SKU and its payment deep link
        // carries no amount, so only the configured wallet presets are payable
        // here. Each preset defines the SKU to use (admin panel → business
        // settings); the client hides the custom amount field inside Bazaar and
        // this guard enforces the same rule server-side.
        if (! SettingsService::isWalletTopUpPreset($amount)) {
            return response()->json([
                'error' => 'داخل اپ کافه‌بازار فقط مبالغ پیشنهادی قابل پرداخت است (هر بسته با یک SKU قیمت ثابت هم‌تراز شده است).',
                'code' => 'BAZAAR_FIXED_AMOUNT',
            ], 400);
        }

        // SKU mapped to this amount, falling back to the optional default SKU.
        $sku = SettingsService::walletTopUpSkuFor($amount)
            ?: (string) SettingsService::bazaarIapSettings()['sku'];
        if ($sku === '') {
            return response()->json([
                'error' => 'برای این مبلغ شارژ، SKU کافه‌بازار تعریف نشده است. آن را همراه با مبلغ در «تنظیمات کسب‌وکار» وارد کنید.',
                'code' => 'BAZAAR_SKU_MISSING',
            ], 400);
        }

        $visitId = (string) Str::uuid();
        $returnUrl = SettingsService::appUrl() ?: '/';

        $redirectUrl = BazaarIap::redirectUrl([
            'visit' => $visitId,
            'status' => 'payment',
            'sku' => $sku,
            'return' => $returnUrl,
        ]);
        $deepLink = BazaarIap::paymentDeepLink($redirectUrl, $sku);

        // Login-first variant for buyers without a Cafe Bazaar account id: the
        // browser logs in, Bazaar returns to the callback with status=login,
        // and the callback resumes the payment automatically.
        $loginRedirect = BazaarIap::redirectUrl([
            'visit' => $visitId,
            'status' => 'login',
            'sku' => $sku,
            'return' => $returnUrl,
        ]);
        $loginDeepLink = BazaarIap::loginDeepLink($loginRedirect, $sku);

        $payment = Payment::create([
            'order_id' => null,
            'user_id' => $user->id,
            'purpose' => 'wallet',
            'amount' => $amount,
            'authority' => $visitId,
            'gateway' => 'bazaar',
            'status' => 'pending',
            'created_at' => $now,
        ]);

        return response()->json([
            'gateway' => 'bazaar',
            'paymentId' => $payment->id,
            'authority' => $visitId,
            'deepLink' => $deepLink,
            'loginDeepLink' => $loginDeepLink,
            'redirectUrl' => $redirectUrl,
        ]);
    }
}
