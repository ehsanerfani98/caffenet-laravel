<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BazaarPayContract;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Services\BazaarPayService;
use App\Services\PaymentFulfillmentService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Caffenet\BazaarIap\Facades\BazaarIap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payments — gateway entry points.
 *
 * Wallet top-ups (purpose=wallet) go through the online gateways; services are
 * paid from the wallet (OrderController::payWithWallet) or — when the BazaarPay
 * Direct Pay contract flow is enabled — directly through BazaarPay
 * (requestDirectOrder). Amounts are validated against MIN/MAX bounds and never
 * trusted from the client.
 *
 * BazaarPay (بازارپی) flows (docs: github.com/BazaarPay/Docs/tree/main/fa):
 *  - requestBazaarPay:  checkout/init → user pays on app.bazaar-pay.ir →
 *    /verify callback traces + commits → wallet credited.
 *  - requestDirectOrder: active contract → checkout/init + direct-pay +
 *    commit happen server-side in one shot; without an active contract the
 *    user is sent to the signing page and the contract callback resumes the
 *    payment automatically.
 *  - verify/status: trace-driven and idempotent — a lost callback self-heals
 *    on the next status poll.
 */
class PaymentController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $body = (array) $request->json()->all();
        $now = now()->toIso8601String();

        // Services are paid from the wallet (or BazaarPay Direct Pay for
        // orders). This endpoint exists solely for wallet top-ups, so any
        // other purpose is rejected outright.
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

        // Gateway selection: `online` (Zarinpal, default), `bazaarpay`
        // (BazaarPay web gateway for the WebView app) or `bazaar` (legacy
        // Cafe Bazaar in-app purchase via deep link).
        $gatewayKind = match ($body['gateway'] ?? 'online') {
            'bazaarpay' => 'bazaarpay',
            'bazaar' => 'bazaar',
            default => 'online',
        };

        if ($gatewayKind === 'bazaar') {
            return $this->requestBazaar($user, $amount, $now);
        }

        if ($gatewayKind === 'bazaarpay') {
            return $this->requestBazaarPay($user, $amount, $now);
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
     * Handles Zarinpal (Authority/Status) and BazaarPay (trace-driven).
     */
    public function verify(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        // BazaarPay: the checkout token is embedded in redirect_url as
        // `authority`; BazaarPay's own params (`token`, …) are accepted too.
        $authority = (string) $request->query('Authority', $request->query('authority', ''));
        $status = (string) $request->query('Status', $request->query('status', ''));
        if ($authority === '') {
            $authority = (string) $request->query('token', $request->query('checkout_token', ''));
        }

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

        // ── BazaarPay branch: the redirect params are never trusted; the
        // trace endpoint is the single source of truth. The Status param only
        // matters for TEST- tokens (internal /test-gateway uses it for
        // success/cancel) — real gateway returns are always re-traced. ──
        if ($payment->gateway === 'bazaarpay') {
            $outcome = $this->verifyBazaarPay($payment, $status);

            return redirect()->to($outcome === 'paid' ? $successUrl : $failUrl);
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

        // BazaarPay self-healing: a pending checkout is re-traced so a lost or
        // never-delivered gateway callback cannot leave the wallet uncredited.
        if ($payment->status === 'pending' && $payment->gateway === 'bazaarpay' && (string) $payment->authority !== '') {
            $this->reconcileBazaarPay($payment);
            $payment->refresh();
        }

        return response()->json([
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'amount' => (int) $payment->amount,
            'refId' => $payment->ref_id,
            'orderId' => $payment->order_id,
        ]);
    }

    // ───────────────────────────────────────────── BazaarPay: wallet top-up ──

    /**
     * BazaarPay checkout gateway — wallet top-up. Creates the checkout token
     * server-side and returns the gateway URL the SPA redirects to. Any amount
     * within the MIN/MAX bounds is accepted (no SKU presets — unlike the
     * legacy in-app IAP gateway, BazaarPay is a real server-side gateway).
     */
    private function requestBazaarPay($user, int $amount, string $now): JsonResponse
    {
        $cfg = SettingsService::bazaarPaySettings();
        if (! $cfg['enabled']) {
            return response()->json(['error' => 'درگاه پرداخت بازارپی فعال نیست.'], 400);
        }
        if (! $cfg['available']) {
            return response()->json(['error' => 'توکن مرچنت بازارپی تنظیم نشده است؛ آن را در تنظیمات سیستمی وارد کنید.'], 400);
        }

        $init = BazaarPayService::initCheckout($amount, 'شارژ کیف پول');
        if (! $init['ok']) {
            return response()->json(['error' => $init['error']], 400);
        }

        $payment = Payment::create([
            'order_id' => null,
            'user_id' => $user->id,
            'purpose' => 'wallet',
            'amount' => $amount,
            'authority' => $init['checkoutToken'],
            'gateway' => 'bazaarpay',
            'status' => 'pending',
            'created_at' => $now,
        ]);

        // Test mode points straight at the internal test gateway page.
        $redirectUrl = $cfg['testMode']
            ? $init['paymentUrl']
            : BazaarPayService::gatewayRedirectUrl($init['paymentUrl'], $init['checkoutToken'], (string) $user->phone);

        return response()->json([
            'gateway' => 'bazaarpay',
            'paymentId' => $payment->id,
            'authority' => $init['checkoutToken'],
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * Trace-driven decision for a returning BazaarPay checkout. Never trusts
     * redirect params; commits paid checkouts and applies the fulfilment.
     *
     * @return string 'paid' | 'failed' (pending keeps the success redirect —
     *                the pollable status endpoint reconciles it later)
     */
    private function verifyBazaarPay(Payment $payment, string $redirectStatus = ''): string
    {
        // Test-mode checkouts (internal /test-gateway) resolve through the
        // Status param: OK = paid, anything else = cancelled by the tester.
        if (str_starts_with((string) $payment->authority, 'TEST-')) {
            if (strtoupper($redirectStatus) !== 'OK') {
                PaymentFulfillmentService::markFailed($payment, 'انصراف از درگاه آزمایشی.');

                return 'failed';
            }
            PaymentFulfillmentService::apply($payment, $payment->authority, null, 'bazaarpay');

            return 'paid';
        }

        $outcome = $this->reconcileBazaarPay($payment);
        if ($outcome === 'failed') {
            PaymentFulfillmentService::markFailed($payment, 'تراکنش بازارپی ناموفق بود یا لغو شد.');
        }

        return $outcome;
    }

    /**
     * Single source of truth for a pending BazaarPay checkout: trace → commit
     * → fulfil. Safe to call repeatedly (idempotent, guarded transitions).
     *
     * @return string 'paid' | 'pending' | 'failed'
     */
    private function reconcileBazaarPay(Payment $payment): string
    {
        if ($payment->status === 'paid') {
            return 'paid';
        }

        $traced = BazaarPayService::trace((string) $payment->authority);
        if (! $traced['ok']) {
            return 'pending'; // transient gateway error — retry later
        }

        $status = $traced['status'];

        if ($status === BazaarPayService::T_PAID_NOT_COMMITTED) {
            // Confirm service delivery so the money does not bounce back.
            $commit = BazaarPayService::commit((string) $payment->authority);
            if (! $commit['ok']) {
                return 'pending'; // commit retried on the next poll
            }
            PaymentFulfillmentService::apply($payment, $payment->authority, null, 'bazaarpay');

            return 'paid';
        }

        if ($status === BazaarPayService::T_PAID_COMMITTED) {
            PaymentFulfillmentService::apply($payment, $payment->authority, null, 'bazaarpay');

            return 'paid';
        }

        if (in_array($status, BazaarPayService::FAILED_STATUSES, true)) {
            return 'failed';
        }

        // unpaid — the user has not finished paying yet.
        return 'pending';
    }

    // ─────────────────────────────────────────── BazaarPay: direct pay ──

    /**
     * Direct Pay for an order (POST /orders/{id}/pay-bazaarpay).
     *
     *  - Active contract  → checkout + direct-pay + commit run server-side in
     *    one shot and the response carries the final status.
     *  - No active contract → a contract is created and
     *    `contract_pending` + the signing URL are returned; the contract
     *    callback finishes the payment once the user signs.
     */
    public function requestDirectOrder(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }
        if ($order->customer_id !== $user->id) {
            return response()->json(['error' => 'پرداخت این سفارش برای شما مجاز نیست.'], 403);
        }
        if ((int) $order->paid_amount > 0) {
            return response()->json(['error' => 'این سفارش قبلاً پرداخت شده است.'], 400);
        }
        $payable = max(0, (int) $order->total_price - (int) $order->discount_amount);
        if ($payable <= 0) {
            return response()->json(['error' => 'مبلغ قابل پرداخت نامعتبر است.'], 400);
        }

        $cfg = SettingsService::bazaarPaySettings();
        if (! $cfg['directEnabled']) {
            return response()->json(['error' => 'پرداخت مستقیم بازارپی فعال نیست.'], 400);
        }

        $now = now()->toIso8601String();

        // Reuse the pending row created by an earlier attempt (idempotent
        // restarts), otherwise create it.
        $payment = Payment::where('order_id', $order->id)
            ->where('gateway', 'bazaarpay')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();
        if (! $payment) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'purpose' => 'order',
                'amount' => $payable,
                'authority' => null,
                'gateway' => 'bazaarpay',
                'status' => 'pending',
                'created_at' => $now,
            ]);
        }

        // Active contract? (state is refreshed from the gateway trace)
        $contract = $this->activeContractFor($user->id);

        if ($contract) {
            $result = $this->executeDirectPay($payment, $contract);

            return match ($result['status']) {
                'paid' => response()->json([
                    'status' => 'paid',
                    'message' => 'پرداخت مستقیم با موفقیت انجام شد.',
                    'orderId' => $order->id,
                ]),
                'pending' => response()->json([
                    'status' => 'pending',
                    'message' => 'پرداخت در حال بررسی است؛ وضعیت سفارش به‌صورت خودکار به‌روز می‌شود.',
                    'orderId' => $order->id,
                ]),
                default => response()->json([
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'پرداخت مستقیم ناموفق بود.',
                ], 400),
            };
        }

        // No active contract → create one and send the user to sign it.
        $init = BazaarPayService::initContract($cfg['directType'], $cfg['directPeriod'], $cfg['directAmountLimit']);
        if (! $init['ok']) {
            return response()->json(['error' => $init['error']], 400);
        }

        $contract = BazaarPayContract::create([
            'user_id' => $user->id,
            'contract_token' => $init['contractToken'],
            'type' => $cfg['directType'],
            'period' => $cfg['directPeriod'],
            'amount_limit' => $cfg['directAmountLimit'],
            'state' => BazaarPayContract::STATE_NEW,
            'created_at' => $now,
        ]);

        // Link the pending payment to this contract so the callback can resume.
        DB::table('payments')->where('id', $payment->id)->update([
            'contract_token' => $init['contractToken'],
            'amount' => $payable,
        ]);

        $signUrl = BazaarPayService::contractPageUrl(
            $init['contractToken'],
            (string) $user->phone,
            'امضای قرارداد پرداخت مستقیم کافی‌نت احسان'
        );

        return response()->json([
            'status' => 'contract_pending',
            'message' => 'برای فعال‌سازی پرداخت مستقیم، قرارداد را در صفحه بازارپی تایید کنید.',
            'contractRedirectUrl' => $signUrl,
            'paymentId' => $payment->id,
            'orderId' => $order->id,
        ]);
    }

    /**
     * Direct-pay contract return (GET /api/payments/bazaarpay/contract) — the
     * redirect_url handed to the signing page. Public by design (the token in
     * the URL identifies the row, same trust level as /verify).
     */
    public function contractCallback(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $appUrl = SettingsService::appUrl();
        $failUrl = ($appUrl ?: '').'/?payment=failed';
        $successUrl = ($appUrl ?: '').'/?payment=success';

        $contractToken = (string) $request->query('contract_token', $request->query('token', ''));
        if ($contractToken === '') {
            return redirect()->to($failUrl);
        }

        $contract = BazaarPayContract::where('contract_token', $contractToken)->first();
        if (! $contract) {
            return redirect()->to($failUrl);
        }

        // Refresh the contract state from the gateway.
        $traced = BazaarPayService::traceContract($contractToken);
        $state = $traced['ok'] ? $traced['state'] : $contract->state;
        $this->updateContractState($contract, $state, $traced);

        $payment = Payment::where('contract_token', $contractToken)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        if ($state !== BazaarPayContract::STATE_ACTIVE) {
            if ($payment && in_array($state, [BazaarPayContract::STATE_DECLINED, BazaarPayContract::STATE_CANCELLED, BazaarPayContract::STATE_EXPIRED], true)) {
                PaymentFulfillmentService::markFailed($payment, 'قرارداد پرداخت مستقیم '.(BazaarPayContract::STATE_TITLES[$state] ?? $state).' است.');
            }

            return redirect()->to($failUrl);
        }

        if (! $payment) {
            // Contract signed but no pending payment left — nothing to resume.
            return redirect()->to($successUrl);
        }

        $result = $this->executeDirectPay($payment, $contract);

        if ($result['status'] === 'paid') {
            return redirect()->to($successUrl.'&authority='.urlencode((string) $payment->authority));
        }

        // pending/failed: money has not moved for sure on 'failed'; on
        // 'pending' the order tracker / status poll will reconcile.
        return redirect()->to($failUrl);
    }

    /**
     * Runs checkout + direct-pay + commit for one pending payment against an
     * active contract. Idempotent: an existing checkout token is reused; a
     * timed-out token is replaced once.
     *
     * @return array{status: string, error?: string} status: paid|pending|failed
     */
    private function executeDirectPay(Payment $payment, BazaarPayContract $contract): array
    {
        $order = $payment->order_id ? Order::find($payment->order_id) : null;
        $serviceName = $order
            ? 'پرداخت سفارش '.$order->order_number
            : 'پرداخت سفارش';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $checkoutToken = (string) $payment->authority;

            if ($checkoutToken === '') {
                $init = BazaarPayService::initCheckout((int) $payment->amount, $serviceName);
                if (! $init['ok']) {
                    return ['status' => 'failed', 'error' => $init['error']];
                }
                $checkoutToken = $init['checkoutToken'];
                DB::table('payments')->where('id', $payment->id)->update([
                    'authority' => $checkoutToken,
                    'contract_token' => $contract->contract_token,
                ]);
                $payment->authority = $checkoutToken;
            }

            $traced = BazaarPayService::trace($checkoutToken);
            if ($traced['ok']) {
                if ($traced['status'] === BazaarPayService::T_PAID_NOT_COMMITTED) {
                    $commit = BazaarPayService::commit($checkoutToken);
                    if (! $commit['ok']) {
                        return ['status' => 'pending'];
                    }
                    PaymentFulfillmentService::apply($payment, $checkoutToken, null, 'bazaarpay');

                    return ['status' => 'paid'];
                }
                if ($traced['status'] === BazaarPayService::T_PAID_COMMITTED) {
                    PaymentFulfillmentService::apply($payment, $checkoutToken, null, 'bazaarpay');

                    return ['status' => 'paid'];
                }
                if (in_array($traced['status'], [BazaarPayService::T_INVALID_TOKEN, BazaarPayService::T_TIMED_OUT, BazaarPayService::T_REFUNDED, BazaarPayService::T_PAID_NOT_COMMITTED_REFUNDED], true)) {
                    // Stale checkout — drop it and build a fresh one once.
                    DB::table('payments')->where('id', $payment->id)->update(['authority' => null]);
                    $payment->authority = null;

                    continue;
                }
                // 'unpaid' → fall through to the actual direct-pay call.
            }

            $pay = BazaarPayService::payWithContract($contract->contract_token, $checkoutToken);
            if (! $pay['ok']) {
                PaymentFulfillmentService::markFailed($payment, $pay['error']);

                return ['status' => 'failed', 'error' => $pay['error']];
            }

            // Money moved — commit so it is not auto-returned.
            $commit = BazaarPayService::commit($checkoutToken);
            if (! $commit['ok']) {
                return ['status' => 'pending']; // reconciled by the status poll
            }
            PaymentFulfillmentService::apply($payment, $checkoutToken, null, 'bazaarpay');

            return ['status' => 'paid'];
        }

        return ['status' => 'failed', 'error' => 'ایجاد توکن پرداخت بازارپی ناموفق بود.'];
    }

    /**
     * The user's active direct-pay contract, refreshed from the gateway.
     * Falls back to the cached state when the trace call fails transiently.
     */
    private function activeContractFor(string $userId): ?BazaarPayContract
    {
        $contract = BazaarPayContract::where('user_id', $userId)
            ->where('state', BazaarPayContract::STATE_ACTIVE)
            ->orderByDesc('created_at')
            ->first();
        if (! $contract) {
            return null;
        }

        $traced = BazaarPayService::traceContract($contract->contract_token);
        if ($traced['ok']) {
            $this->updateContractState($contract, $traced['state'], $traced);
            if ($traced['state'] !== BazaarPayContract::STATE_ACTIVE) {
                return null;
            }
        }

        return $contract;
    }

    /** Persist the gateway state + limit snapshot onto a contract row. */
    private function updateContractState(BazaarPayContract $contract, string $state, array $traced): void
    {
        $update = [
            'state' => $state,
            'updated_at' => now()->toIso8601String(),
        ];
        if ($state === BazaarPayContract::STATE_ACTIVE && ! $contract->activated_at) {
            $update['activated_at'] = now()->toIso8601String();
        }
        if (isset($traced['expirationTime'])) {
            $update['expiration_time'] = (string) $traced['expirationTime'];
        }
        if (isset($traced['limitExpirationTime'])) {
            $update['limit_expiration_time'] = (string) $traced['limitExpirationTime'];
        }
        if (isset($traced['limitRemaining']) && $traced['limitRemaining'] !== null) {
            $update['limit_remaining_amount'] = (int) $traced['limitRemaining'];
        }

        DB::table('bazaarpay_contracts')->where('id', $contract->id)->update($update);
        $contract->state = $state;
    }

    /**
     * Legacy Cafe Bazaar in-app gateway — wallet top-up only. Builds the deep link and
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
