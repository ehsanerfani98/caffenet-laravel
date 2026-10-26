<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PaymentFulfillmentService;
use App\Services\PricingService;
use App\Services\SettingsService;
use App\Services\SmsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orders — faithful port of ordersRouter in routes.ts.
 *
 * Payment-centric lifecycle guarantees preserved:
 *  - Creation starts at pending_payment with paid_amount = 0 — EXCEPT the
 *    «حالت ثبت درخواست» (paymentsDisabled + paymentMethod='request'): the
 *    operator reviews the request, announces the price and the customer pays
 *    by any means, after which management registers the payment manually.
 *    There is no payment step to wait for, so the order is born `submitted`
 *    and enters the operator queue IMMEDIATELY.
 *  - Wallet payment is transactional with an atomic unpaid guard
 *    (no double-debit under concurrent requests).
 *  - Amounts are always recomputed server-side from the order record.
 *  - Coupon usage is RESERVED at creation and consumed only on verified payment.
 *  - pending_payment/failed orders never enter the operator queue.
 */
class OrderController extends Controller
{
    /** Owner or staff (admin/operator) access check. */
    public static function canAccess($user, Order $order): bool
    {
        if ($user->role === 'admin' || $user->role === 'operator') {
            return true;
        }

        return $order->customer_id === $user->id;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $orders = $user->role === 'customer'
            ? Order::where('customer_id', $user->id)->orderByDesc('created_at')->get()
            : Order::orderByDesc('created_at')->get();

        return response()->json($orders->map([Formatter::class, 'order'])->values()->all());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }
        if (! self::canAccess($user, $order)) {
            return response()->json(['error' => 'دسترسی به این سفارش مجاز نیست.'], 403);
        }

        return response()->json(Formatter::order($order));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        // Only customers may place an order for themselves. Staff accounts
        // (operator/admin) are not customers, so they must never create an
        // order that would make them the customer of record.
        if ($user->role !== 'customer') {
            return response()->json(['error' => 'ثبت سفارش تنها با حساب مشتری امکان‌پذیر است.'], 403);
        }

        $body = (array) $request->json()->all();

        $service = Service::where('id', $body['serviceId'] ?? '')->where('is_active', true)->first();
        if (! $service) {
            return response()->json(['error' => 'خدمت انتخاب‌شده معتبر یا فعال نیست.'], 400);
        }

        // «سرویس بسته» (notice_type = lock): the service is visible in the
        // catalog but cannot take orders until further notice — the client
        // shows the red popup instead of the order form; the API mirrors the
        // same contract so a crafted request cannot slip past the UI.
        if ($service->notice_type === 'lock') {
            $notice = trim((string) $service->notice_text);

            return response()->json([
                'error' => $notice !== '' ? $notice : 'این خدمت تا اطلاع ثانوی قابل ثبت سفارش نیست.',
            ], 400);
        }

        $formData = is_array($body['formData'] ?? null) ? $body['formData'] : [];
        $contactMethods = self::sanitizeContactMethods($body['contactMethods'] ?? []);
        if (count($contactMethods) === 0) {
            return response()->json(['error' => 'حداقل یک راه ارتباطی را انتخاب کنید.'], 400);
        }
        $urgency = PricingService::isValidUrgency($body['urgency'] ?? null) ? $body['urgency'] : 'normal';
        $deliveryMethod = PricingService::isValidDelivery($body['deliveryMethod'] ?? null) ? $body['deliveryMethod'] : 'download';

        if ($deliveryMethod === 'courier' && trim((string) ($body['deliveryAddress'] ?? '')) === '') {
            return response()->json(['error' => 'برای ارسال با پیک، وارد کردن آدرس الزامی است.'], 400);
        }

        // Required-field validation against the dynamic form definition.
        foreach ($service->fields() as $field) {
            if (! empty($field['required'])) {
                $val = $formData[$field['id']] ?? null;
                if ($val === null || $val === '' || (is_array($val) && count($val) === 0)) {
                    return response()->json(['error' => "تکمیل فیلد «{$field['label']}» الزامی است."], 400);
                }
            }
        }

        $mapped = Formatter::service($service);
        $pricing = PricingService::calculate(
            $mapped,
            $formData,
            $urgency,
            $deliveryMethod,
            \App\Services\SettingsService::urgencyMultipliers(),
            \App\Services\SettingsService::courierCost()
        );

        $discount = 0;
        $appliedCoupon = null;
        if (! empty($body['couponCode']) && \App\Services\SettingsService::bool('enableCoupons')) {
            $result = self::evaluateCoupon((string) $body['couponCode'], $pricing['totalBeforeDiscount']);
            if ($result['valid']) {
                $discount = $result['discount'];
                $appliedCoupon = $result['coupon'];
            }
        }

        $payable = max(0, (int) $pricing['totalBeforeDiscount'] - $discount);

        // Payment method declared by the client: 'wallet' (default) or
        // 'bazaarpay' (the BazaarPay gateway — checkout redirect, upgraded to
        // silent Direct Pay automatically when a contract is active). The
        // intent is honored whenever the BazaarPay gateway itself is available
        // (enabled + token, or test mode) — a client sending bazaarpay while
        // the whole gateway is disabled silently falls back to wallet so the
        // balance guard below still applies.
        $bpCfg = \App\Services\SettingsService::bazaarPaySettings();
        $paymentMethod = (($body['paymentMethod'] ?? null) === 'bazaarpay' && $bpCfg['available'])
            ? 'bazaarpay'
            : 'wallet';

        // «حالت ثبت درخواست»: while the admin has disabled all payments, a
        // customer may submit the order as a plain request — no wallet debit
        // and no gateway. Honored ONLY while paymentsDisabled is on, so a
        // tampered client can never bypass the balance guard by sending
        // 'request' at any other time (it silently falls back to 'wallet').
        if (($body['paymentMethod'] ?? null) === 'request' && \App\Services\SettingsService::bool('paymentsDisabled')) {
            $paymentMethod = 'request';
        }

        // Wallet payments require sufficient balance up-front — never persist
        // an order the customer cannot pay for; the client shows a shortage
        // warning and sends the user to top up instead. BazaarPay has no such
        // requirement (money comes from the gateway page or the user's Bazaar
        // wallet/bank account), so the balance check is skipped for it.
        if ($paymentMethod === 'wallet') {
            $freshUser = User::find($user->id);
            $balance = (int) ($freshUser->wallet_balance ?? 0);
            if ($balance < $payable) {
                return response()->json([
                    'error' => 'موجودی کیف پول کافی نیست؛ لطفاً ابتدا کیف پول خود را شارژ کنید.',
                    'code' => 'INSUFFICIENT_WALLET',
                    'required' => $payable,
                    'balance' => $balance,
                    'shortfall' => max(0, $payable - $balance),
                ], 400);
            }
        }

        $order = null;
        DB::transaction(function () use (&$order, $user, $service, $body, $formData, $contactMethods, $urgency, $deliveryMethod, $pricing, $discount, $appliedCoupon, $paymentMethod) {
            // Smart queue: classify the order type from the service's
            // estimated_time text (or honor an explicit client override).
            $classifier = app(\App\Services\Queue\OrderTypeClassifierService::class);
            $queueType = $classifier->classify(
                $service->estimated_time ?? null,
                \App\Models\OrderType::KEY_NORMAL,
            );

            // «حالت ثبت درخواست»: no payment step exists — the operator
            // reviews the request and announces the price, and the customer
            // pays afterwards (management then registers the payment
            // manually). Waiting for a payment that will never come through
            // the app would strand the order in pending_payment (where the
            // 30-minute abandonment cleanup would kill it), so the order is
            // born `submitted` and goes straight into the operator queue.
            $initialStatus = $paymentMethod === 'request'
                ? Order::STATUS_SUBMITTED
                : Order::STATUS_PENDING_PAYMENT;

            // Request mode («غیرفعال کردن همه پرداخت‌ها»): the urgency picker
            // is hidden on the form, so the quick/normal/long ladder does not
            // apply — the work duration is a random draw from the
            // admin-configured [min, max] minutes window. The queue estimator
            // sums these per-order durations for every customer's wait display.
            $requestDuration = $paymentMethod === 'request'
                ? self::drawRequestDurationMinutes()
                : null;

            $order = Order::create([
                'order_number' => self::generateOrderNumber(),
                'service_id' => $service->id,
                'service_title' => $service->title,
                'customer_id' => $user->id,
                'customer_phone' => $user->phone,
                'customer_name' => $user->name,
                'status' => $initialStatus,
                'urgency' => $urgency,
                // Smart queue fields (set at creation; consumed when the
                // order transitions to `submitted` after payment):
                'queue_type' => $queueType,
                'priority_level' => $queueType === \App\Models\OrderType::KEY_QUICK ? 9
                    : ($queueType === \App\Models\OrderType::KEY_LONG ? 3 : 5),
                'estimated_duration_minutes' => $requestDuration,
                'delivery_method' => $deliveryMethod,
                'delivery_address' => $deliveryMethod === 'courier' ? (string) $body['deliveryAddress'] : null,
                'delivery_postal_code' => $deliveryMethod === 'courier' ? ((string) ($body['deliveryPostalCode'] ?? '') ?: null) : null,
                'form_data_json' => json_encode($formData, JSON_UNESCAPED_UNICODE),
                'customer_files_json' => json_encode(self::sanitizeFiles($body['customerFiles'] ?? []), JSON_UNESCAPED_UNICODE),
                'result_files_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'total_price' => $pricing['totalBeforeDiscount'],
                'discount_amount' => $discount,
                'paid_amount' => 0,
                // Payment INTENT at creation ('wallet' | 'bazaarpay' |
                // 'request'); the final method is recorded on the order row by
                // PaymentFulfillmentService once the payment verifies.
                'payment_method' => $paymentMethod,
                'payment_tracking_code' => '',
                'customer_note' => isset($body['customerNote']) ? (string) $body['customerNote'] : null,
                'contact_methods_json' => json_encode($contactMethods, JSON_UNESCAPED_UNICODE),
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);

            OrderLog::create([
                'order_id' => $order->id,
                'action' => $paymentMethod === 'request'
                    ? 'ثبت درخواست (پرداخت غیرفعال)؛ ورود مستقیم به صف اپراتور'
                    : 'ثبت سفارش؛ در انتظار پرداخت',
                'performed_by' => $user->name,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Reserve (never consume) the coupon until payment verifies.
            if ($appliedCoupon) {
                DB::table('orders')->where('id', $order->id)->update(['coupon_id' => $appliedCoupon->id]);
            }
        });

        // «حالت ثبت درخواست» — payment condition removed from the SMS chain.
        // With the paymentsDisabled switch on there is no payment step, so the
        // order-confirmation SMS (the smsPatternPaid pattern that normally
        // fires right after a verified payment) is sent immediately when the
        // request is registered. $paymentMethod can only be 'request' while
        // paymentsDisabled is on (guarded above).
        if ($paymentMethod === 'request') {
            SmsService::sendOrderNotification(
                $order->customer_phone,
                'paid',
                [
                    'order_number'   => $order->order_number,
                    'customer_name'  => $order->customer_name,
                    'amount'         => (string) $payable,
                    'payment_method' => 'request',
                ]
            );

            // Management notification: a NEW order just entered the system
            // (sent to the single admin-configured number; a no-op while it
            // is empty). The send itself is self-contained and never throws.
            SmsService::sendNewOrderAdminNotification($order->order_number);

            // ── Smart queue: the request goes straight into the operator queue ──
            // Same enqueue the paid fulfillment path uses (ETA + track token
            // + queue position). Non-fatal: a queue hiccup must never fail an
            // already-created order.
            try {
                app(\App\Services\Queue\OrderLifecycleService::class)->onSubmitted($order->fresh());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('OrderController: request-mode onSubmitted failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(Formatter::order($order->fresh()), 201);
    }

    /** Transactional wallet payment with an atomic unpaid guard. */
    public function payWithWallet(Request $request, string $id): JsonResponse
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

        // Wallet is the only way to pay for a service, so this path is not
        // gated by any toggle. The balance is re-read here (authoritative
        // check) and a structured shortage payload is returned so the UI can
        // offer a top-up instead of a generic failure.
        $freshUser = User::find($user->id);
        $balance = (int) $freshUser->wallet_balance;
        if ($balance < $payable) {
            return response()->json([
                'error' => 'موجودی کیف پول کافی نیست؛ لطفاً ابتدا کیف پول خود را شارژ کنید.',
                'code' => 'INSUFFICIENT_WALLET',
                'required' => $payable,
                'balance' => $balance,
                'shortfall' => max(0, $payable - $balance),
            ], 400);
        }

        $trackingCode = 'WLT-'.random_int(100000, 999999);
        $now = now()->toIso8601String();
        // Whether this payment is what brings the order INTO the system (a
        // pre-payment order becoming submitted). Captured inside the
        // transaction from the locked pre-payment row; used post-commit for
        // the queue enqueue AND the management notification.
        $enteredQueue = false;

        try {
            DB::transaction(function () use ($order, $user, $payable, $trackingCode, $now, &$enteredQueue) {
                // Atomic idempotency guard: re-read inside the transaction and
                // only proceed when still unpaid (anti double-debit race).
                $fresh = DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
                if (! $fresh || (int) $fresh->paid_amount > 0) {
                    throw new \RuntimeException('ORDER_ALREADY_PAID');
                }

                // Payment that lifts a pre-payment order (pending_payment /
                // failed) into submitted IS the moment the order enters the
                // system. A request-mode order (paymentsDisabled) was already
                // announced at registration — its status is preserved here.
                $enteredQueue = in_array($fresh->status, [Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED], true);

                WalletService::debit($user, $payable, WalletTransaction::TYPE_PURCHASE, [
                    'orderId' => $order->id,
                    'reference' => $trackingCode,
                    'note' => "پرداخت سفارش {$order->order_number}",
                    'performedById' => $user->id,
                    'performedByName' => $user->name,
                ]);

                $affected = DB::table('orders')
                    ->where('id', $order->id)
                    ->where('paid_amount', 0)
                    ->update([
                        'paid_amount' => $payable,
                        'payment_method' => 'wallet',
                        'payment_tracking_code' => $trackingCode,
                        // A request-mode order (paymentsDisabled) may already
                        // be sitting in the queue (submitted/processing) when
                        // the customer pays — never demote it back to
                        // submitted; only the pre-payment states advance.
                        'status' => in_array($fresh->status, [Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED], true)
                            ? Order::STATUS_SUBMITTED
                            : $fresh->status,
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw new \RuntimeException('WALLET_DEBIT_LOST_RACE');
                }

                OrderLog::create([
                    'order_id' => $order->id,
                    'action' => 'پرداخت از کیف پول با موفقیت انجام شد',
                    'performed_by' => $user->name,
                    'timestamp' => $now,
                ]);

                Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'purpose' => 'order',
                    'amount' => $payable,
                    'gateway' => 'wallet',
                    'status' => 'paid',
                    'created_at' => $now,
                    'verified_at' => $now,
                ]);

                // Consume the reserved coupon now that payment succeeded.
                if ($fresh->coupon_id) {
                    DB::table('coupons')->where('id', $fresh->coupon_id)->increment('usage_count');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_WALLET_BALANCE') {
                // Rare race: the balance changed between the pre-check and the
                // debit. No order state was touched (the transaction rolled back).
                $balance = (int) DB::table('users')->where('id', $user->id)->value('wallet_balance');

                return response()->json([
                    'error' => 'موجودی کیف پول کافی نیست؛ لطفاً ابتدا کیف پول خود را شارژ کنید.',
                    'code' => 'INSUFFICIENT_WALLET',
                    'required' => $payable,
                    'balance' => $balance,
                    'shortfall' => max(0, $payable - $balance),
                ], 400);
            }
            if (in_array($e->getMessage(), ['ORDER_ALREADY_PAID', 'WALLET_DEBIT_LOST_RACE'], true)) {
                return response()->json(['error' => 'این سفارش قبلاً پرداخت شده است.'], 400);
            }
            throw $e;
        }

        // SMS: pattern-based — uses smsPatternPaid + smsPatternPaidVars from settings.
        // The model was read before the paying transaction — re-read it so the
        // receipt carries the real paid amount and final payment method.
        $order = $order->fresh() ?? $order;
        SmsService::sendOrderNotification(
            $order->customer_phone,
            'paid',
            [
                'order_number'   => $order->order_number,
                'customer_name'  => $order->customer_name,
                'amount'         => (string) $order->paid_amount,
                'payment_method' => $order->payment_method,
            ]
        );

        // Management notification: the payment just brought a NEW order into
        // the system. Request-mode orders were already announced at
        // registration (enteredQueue = false) — never notified twice.
        if ($enteredQueue) {
            SmsService::sendNewOrderAdminNotification($order->order_number);
        }

        // ── Smart queue: enqueue the now-submitted order ──
        // Only when the payment moved the order into the queue (a
        // pre-payment status becoming submitted). A request-mode order was
        // enqueued at submission — onSubmitted's own entry guard would also
        // catch it, but skipping here keeps intent explicit.
        if ($enteredQueue) {
            try {
                app(\App\Services\Queue\OrderLifecycleService::class)->onSubmitted($order);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('OrderController: onSubmitted failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(Formatter::order($order->fresh()));
    }

    /**
     * Manual (offline) payment registration by management — POST /orders/{id}/manual-payment.
     *
     * Replaces the online payment steps: the admin records that the customer
     * paid in person (cash, card terminal, receipt…), the order is marked
     * paid exactly like a verified gateway payment (including the receipt
     * SMS, the wallet-ledger transaction and the smart-queue enqueue when the
     * order was not already queued). Available in ALL conditions — with the
     * paymentsDisabled switch on or off — for any still-unpaid order that has
     * not reached a terminal state: pending_payment / failed (classic unpaid
     * orders) and submitted / processing / ready (request-mode orders that
     * entered the queue at submission and are paid later, on the operator's
     * price announcement).
     *
     * Body (all optional except the path id):
     *  - amount    (integer) defaults to the order's payable (total - discount)
     *  - reference (string)  manual tracking code / receipt number
     *  - note      (string)  free-form note recorded in the order log
     */
    public function registerManualPayment(Request $request, string $id): JsonResponse
    {
        $actor = $request->attributes->get('authUser');
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }
        if ((int) $order->paid_amount > 0) {
            return response()->json(['error' => 'این سفارش قبلاً پرداخت شده است.'], 400);
        }
        // Only states that can still receive money accept a manual payment:
        // the classic unpaid states plus the queue states a request-mode order
        // occupies (it entered the queue at submission and is paid later).
        // cancelled/delivered/refunded orders must not be resurrected here.
        if (! in_array($order->status, [
            Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED,
            Order::STATUS_SUBMITTED, Order::STATUS_PROCESSING, Order::STATUS_READY,
        ], true)) {
            return response()->json(['error' => 'این سفارش در وضعیت قابل ثبت پرداخت دستی نیست.'], 400);
        }

        $body = (array) $request->json()->all();
        $payable = max(0, (int) $order->total_price - (int) $order->discount_amount);
        $amount = array_key_exists('amount', $body) && $body['amount'] !== null && $body['amount'] !== ''
            ? (int) $body['amount']
            : $payable;
        if ($amount <= 0) {
            return response()->json(['error' => 'مبلغ پرداخت دستی نامعتبر است.'], 400);
        }

        $reference = trim((string) ($body['reference'] ?? ''));
        $note = trim((string) ($body['note'] ?? ''));
        $trackingCode = $reference !== '' ? $reference : 'MAN-'.random_int(100000, 999999);

        // A pending payment row records the manual transaction, then the
        // shared fulfillment service applies the paid transition exactly
        // once (idempotent guarded UPDATE) — same SMS + queue + coupon
        // behavior as a gateway payment.
        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->customer_id,
            'purpose' => 'order',
            'amount' => $amount,
            'gateway' => 'manual',
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ]);

        $applied = PaymentFulfillmentService::apply($payment, $trackingCode, null, 'manual', $actor->name);
        if (! $applied) {
            // Lost a concurrent race — the order was paid by another path
            // between the guard and the transaction. Close the pending row
            // so no orphan manual payment lingers, change nothing else.
            PaymentFulfillmentService::markFailed($payment, 'ثبت پرداخت دستی انجام نشد — سفارش همزمان از مسیر دیگری پرداخت شد.');

            return response()->json(['error' => 'این سفارش قبلاً پرداخت شده است.'], 400);
        }

        if ($note !== '') {
            OrderLog::create([
                'order_id' => $order->id,
                'action' => 'توضیح پرداخت دستی',
                'performed_by' => $actor->name,
                'note' => $note,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        $order->refresh();

        return response()->json(Formatter::order($order));
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $status = (string) ($request->json('status') ?? '');
        $note = $request->json('note');

        if (! in_array($status, Order::STATUSES, true)) {
            return response()->json(['error' => 'وضعیت نامعتبر است.'], 400);
        }

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        // Idempotent no-op: the order already carries the requested status.
        // Re-applying it would duplicate history rows for zero effect.
        if ($order->status === $status) {
            return response()->json(Formatter::order($order));
        }

        $title = Order::STATUS_TITLES[$status];
        $now = now()->toIso8601String();

        // ── Smart-queue bookkeeping ─────────────────────────────────────
        // This endpoint is what the operator console (and admin orders UI)
        // actually call. The queue's queue_entries bookkeeping (started_at /
        // finished_at / dequeue) MUST follow the status change, otherwise
        // wait-time estimates keep counting processed orders and displayed
        // positions never advance. Transitions therefore route through
        // OrderLifecycleService, which owns queue_entries mutations.
        $lifecycle = app(\App\Contracts\OrderLifecycleService::class);
        $notifications = app(\App\Contracts\NotificationDispatcherServiceInterface::class);

        if ($status === Order::STATUS_PROCESSING) {
            $lifecycle->onStart($order, $user->id);
        } elseif ($status === Order::STATUS_READY) {
            $lifecycle->onReady($order);
        } elseif ($status === Order::STATUS_DELIVERED) {
            $lifecycle->onDelivered($order);
        } elseif ($status === Order::STATUS_CANCELLED) {
            $lifecycle->onCancel($order);
        } else {
            // pending_payment / failed / refunded — the order has left the
            // working queue; release its queue slot.
            $prevStatus = $order->status;

            $order->status = $status;
            $order->no_response_deadline = null;
            $order->updated_at = $now;
            $order->save();

            app(\App\Contracts\QueueManagerServiceInterface::class)->dequeue($order);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $prevStatus,
                'to_status' => $status,
                'changed_by' => $user->id,
                'changed_at' => $now,
            ]);
        }

        $order->refresh();

        // ── Customer notifications for the mapped transitions ───────────
        // The operator console and admin orders UI call THIS endpoint (the
        // queue's dedicated /start and /complete endpoints are never hit by
        // the frontend), so the push/in-app/SMS notifications that used to
        // live only in QueueOrderController must fire here too. Each
        // dispatcher call writes the in-app NotificationLog row, fires the
        // FCM push (when fcmEnabled) and sends its own pattern SMS
        // (order_started / order_ready / cancelled) when smsNotifyEnabled.
        if ($status === Order::STATUS_PROCESSING) {
            \App\Events\OrderStarted::dispatch($order, $user->id);
        } elseif ($status === Order::STATUS_READY) {
            $notifications->notifyOrderReady($order);
        } elseif ($status === Order::STATUS_CANCELLED) {
            $notifications->notifyOrderCancelled($order);
        }

        OrderLog::create([
            'order_id' => $order->id,
            'action' => "تغییر وضعیت به: {$title}",
            'performed_by' => $user->phone,
            'note' => $note ? (string) $note : null,
            'timestamp' => $now,
        ]);

        // SMS: pattern-based — uses smsPatternStatusChange + smsPatternStatusChangeVars.
        // SKIPPED for the three transitions above: their dispatcher calls
        // already sent the dedicated order_started / order_ready / cancelled
        // pattern — a blanket status_change SMS here would double-text the
        // customer. delivered/failed/refunded/submitted keep this generic SMS.
        $hasDedicatedNotification = in_array($status, [
            Order::STATUS_PROCESSING, Order::STATUS_READY, Order::STATUS_CANCELLED,
        ], true);

        if (! $hasDedicatedNotification) {
            SmsService::sendOrderNotification(
                $order->customer_phone,
                'status_change',
                [
                    'order_number'  => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'status'        => $order->status,
                    'status_title'  => $title,
                ]
            );
        }

        return response()->json(Formatter::order($order->fresh()));
    }

    /** Rating (owner), internal note + operator assignment (staff). */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $fields = (array) $request->json()->all();
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $isStaff = $user->role === 'admin' || $user->role === 'operator';
        $isOwner = $order->customer_id === $user->id;
        $now = now()->toIso8601String();

        // ── Rating + review: exactly ONE submission per order ────────────
        // The customer composes (stars + comment) and finalises with the
        // «ثبت نظر» action, which submits {rating, reviewComment} together.
        // Once a review has been submitted (review_comment is no longer NULL)
        // BOTH fields are locked for this order — no re-rating, no comment
        // edits, ever. Staff note / operator assignment are unaffected.
        $wantsRating = array_key_exists('rating', $fields);
        $wantsReview = array_key_exists('reviewComment', $fields);

        if ($wantsRating || $wantsReview) {
            if (! $isOwner) {
                return response()->json(['error' => 'ثبت امتیاز تنها توسط مشتری سفارش مجاز است.'], 403);
            }
            if ($order->review_comment !== null) {
                return response()->json([
                    'error' => 'برای این سفارش قبلاً امتیاز و نظر ثبت شده است؛ هر سفارش فقط یکبار قابل امتیازدهی است.',
                ], 409);
            }
            if ($wantsRating) {
                $rating = (int) $fields['rating'];
                if ($rating < 1 || $rating > 5) {
                    return response()->json(['error' => 'امتیاز باید بین ۱ تا ۵ باشد.'], 400);
                }
                $order->rating = $rating;
            }
            // The final submission always carries the comment; a rating-only
            // patch (compose phase) must never wipe an in-progress comment —
            // the old code reset review_comment to NULL on every star tap.
            if ($wantsReview) {
                $order->review_comment = (string) $fields['reviewComment'];
            }
            $order->updated_at = $now;
            $order->save();

            OrderLog::create([
                'order_id' => $order->id,
                'action' => $wantsRating ? "ثبت امتیاز: {$rating} از ۵" : 'ثبت نظر مشتری',
                'performed_by' => $user->name,
                'timestamp' => $now,
            ]);
        }

        if ($isStaff) {
            if (array_key_exists('internalNote', $fields)) {
                $order->internal_note = (string) $fields['internalNote'];
                $order->updated_at = $now;
                $order->save();
            }
            if (array_key_exists('assignedOperatorId', $fields)) {
                $operator = User::find((string) $fields['assignedOperatorId']);
                if ($operator && in_array($operator->role, ['operator', 'admin'], true)) {
                    $order->assigned_operator_id = $operator->id;
                    $order->assigned_operator_name = $operator->name;
                    $order->updated_at = $now;
                    $order->save();

                    OrderLog::create([
                        'order_id' => $order->id,
                        'action' => "ارجاع سفارش به اپراتور: {$operator->name}",
                        'performed_by' => $user->name,
                        'timestamp' => $now,
                    ]);
                }
            }
        }

        if (! $isStaff && ! $isOwner) {
            return response()->json(['error' => 'دسترسی به این سفارش مجاز نیست.'], 403);
        }

        return response()->json(Formatter::order($order->fresh()));
    }

    /** Upload the deliverable output file(s) — orders.edit permission. */
    public function uploadResult(Request $request, string $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:30720']]);

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $file = $request->file('file');
        if (! $file->isValid()) {
            return response()->json(['error' => 'فایل آپلودی معتبر نیست.'], 400);
        }

        $stored = $file->storeAs('', 'out-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'uploads');
        $meta = [
            'id' => 'file-'.Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'url' => '/uploads/'.basename($stored),
            'uploadedAt' => now()->toIso8601String(),
        ];

        $files = $order->resultFiles();
        $files[] = $meta;

        // Replace mode: when the operator replaces an existing file, drop the
        // old entry (metadata + disk copy) within the same request so no stale
        // duplicate is ever left behind.
        $replaceUrl = (string) $request->input('replaceUrl', '');
        $replaced = null;
        if ($replaceUrl !== '') {
            $files = array_values(array_filter($files, function ($f) use ($replaceUrl, &$replaced) {
                if ((string) ($f['url'] ?? '') === $replaceUrl) {
                    $replaced = $f;

                    return false;
                }

                return true;
            }));
            if ($replaced) {
                self::deleteStoredFile($replaceUrl);
            }
        }

        $order->result_files_json = json_encode($files, JSON_UNESCAPED_UNICODE);
        $order->updated_at = now()->toIso8601String();
        $order->save();

        $user = $request->attributes->get('authUser');
        OrderLog::create([
            'order_id' => $order->id,
            'action' => $replaced ? 'جایگزینی فایل خروجی' : 'بارگذاری فایل خروجی',
            'performed_by' => $user->name,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(['file' => $meta, 'order' => Formatter::order($order->fresh())]);
    }

    /** Remove a previously uploaded output file (metadata + disk copy). */
    public function deleteResult(Request $request, string $id): JsonResponse
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $url = (string) ($request->json('url') ?? '');
        if ($url === '') {
            return response()->json(['error' => 'شناسه فایل نامعتبر است.'], 400);
        }

        $found = null;
        $remaining = [];
        foreach ($order->resultFiles() as $f) {
            if ((string) ($f['url'] ?? '') === $url) {
                $found = $f;
                continue;
            }
            $remaining[] = $f;
        }

        if (! $found) {
            return response()->json(['error' => 'فایل یافت نشد.'], 404);
        }

        $order->result_files_json = json_encode(array_values($remaining), JSON_UNESCAPED_UNICODE);
        $order->updated_at = now()->toIso8601String();
        $order->save();

        self::deleteStoredFile($url);

        $user = $request->attributes->get('authUser');
        OrderLog::create([
            'order_id' => $order->id,
            'action' => 'حذف فایل خروجی',
            'performed_by' => $user->name,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(Formatter::order($order->fresh()));
    }

    /** Remove a customer-uploaded attachment (metadata + disk copy) — orders.edit. */
    public function deleteCustomerFile(Request $request, string $id): JsonResponse
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $url = (string) ($request->json('url') ?? '');
        if ($url === '') {
            return response()->json(['error' => 'شناسه فایل نامعتبر است.'], 400);
        }

        $found = null;
        $remaining = [];
        foreach ($order->customerFiles() as $f) {
            if ((string) ($f['url'] ?? '') === $url) {
                $found = $f;
                continue;
            }
            $remaining[] = $f;
        }

        if (! $found) {
            return response()->json(['error' => 'فایل یافت نشد.'], 404);
        }

        $order->customer_files_json = json_encode(array_values($remaining), JSON_UNESCAPED_UNICODE);
        $order->updated_at = now()->toIso8601String();
        $order->save();

        self::deleteStoredFile($url);

        $user = $request->attributes->get('authUser');
        OrderLog::create([
            'order_id' => $order->id,
            'action' => 'حذف فایل پیوست مشتری',
            'performed_by' => $user->name,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(Formatter::order($order->fresh()));
    }

    /** Deletes a private /uploads/... file from the uploads disk (safe no-op otherwise). */
    private static function deleteStoredFile(string $url): void
    {
        if (! str_starts_with($url, '/uploads/')) {
            return;
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');
        if ($name !== '') {
            Storage::disk('uploads')->delete($name);
        }
    }

    /** Order conversation message — owner or staff. */
    public function ticket(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }
        if (! self::canAccess($user, $order)) {
            return response()->json(['error' => 'دسترسی به این سفارش مجاز نیست.'], 403);
        }

        $message = trim((string) ($request->json('message') ?? ''));
        if ($message === '') {
            return response()->json(['error' => 'متن پیام خالی است.'], 400);
        }
        if (mb_strlen($message) > 2000) {
            return response()->json(['error' => 'متن پیام بیش از حد طولانی است.'], 400);
        }

        $isStaff = $user->role === 'admin' || $user->role === 'operator';

        $ticket = TicketMessage::create([
            'order_id' => $order->id,
            'sender_role' => $user->role,
            'sender_name' => $user->name,
            'message' => $message,
            'is_operator_response' => $isStaff,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(Formatter::ticketMessage($ticket));
    }

    /**
     * Mark the conversation as read for the current viewer.
     *
     * Strictly two-party chat: a staff viewer read-receipts the CUSTOMER's
     * messages (staff_read_at) and the owning customer read-receipts the
     * STAFF's replies (customer_read_at). Called when the chat page opens
     * and whenever new messages arrive while it is open — this is what
     * clears the «پیام جدید» badge and the top notification bar.
     */
    public function markTicketRead(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }
        if (! self::canAccess($user, $order)) {
            return response()->json(['error' => 'دسترسی به این سفارش مجاز نیست.'], 403);
        }

        $isStaff = $user->role === 'admin' || $user->role === 'operator';
        $readCol = $isStaff ? 'staff_read_at' : 'customer_read_at';
        $now = now()->toIso8601String();

        $marked = TicketMessage::where('order_id', $order->id)
            ->where('is_operator_response', $isStaff ? false : true)
            ->whereNull($readCol)
            ->update([$readCol => $now]);

        return response()->json(['marked' => $marked, 'unread' => 0]);
    }

    /**
     * Unread-chat summary for the current user — powers BOTH the top
     * notification bar («شما پیام جدید دارید») and the per-order-card
     * «پیام جدید» badge, through one lightweight query.
     *
     * staff viewer  → unread = customer messages with staff_read_at IS NULL
     * customer      → unread = staff replies with customer_read_at IS NULL
     *                 (and only inside their own orders)
     */
    public function chatUnread(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $isStaff = $user->role === 'admin' || $user->role === 'operator';
        $readCol = $isStaff ? 'staff_read_at' : 'customer_read_at';

        $rows = DB::table('ticket_messages as tm')
            ->join('orders as o', 'o.id', '=', 'tm.order_id')
            ->where('tm.is_operator_response', $isStaff ? 0 : 1)
            ->whereNull('tm.'.$readCol)
            ->when(! $isStaff, fn ($q) => $q->where('o.customer_id', $user->id))
            ->orderByDesc('tm.timestamp')
            ->limit(200)
            ->get([
                'tm.order_id', 'tm.message', 'tm.sender_name', 'tm.timestamp',
                'o.order_number', 'o.service_title', 'o.customer_name',
            ]);

        $orders = [];
        foreach ($rows as $r) {
            if (! isset($orders[$r->order_id])) {
                $orders[$r->order_id] = [
                    'orderId' => $r->order_id,
                    'orderNumber' => $r->order_number,
                    'serviceTitle' => $r->service_title,
                    'customerName' => $r->customer_name,
                    'unread' => 0,
                    'lastMessage' => mb_substr((string) $r->message, 0, 90),
                    'lastMessageAt' => $r->timestamp,
                    'lastSenderName' => $r->sender_name,
                ];
            }
            $orders[$r->order_id]['unread']++;
        }

        return response()->json([
            'total' => count($rows),
            'orders' => array_values($orders),
        ]);
    }

    /**
     * vCard (.vcf) download of the order's customer contact for staff.
     *
     * Mobile browsers open the phone's Contacts app with the name + number
     * prefilled when this link is tapped, so the operator can save the
     * customer under any name they like — exactly the «ذخیره مخاطب» flow.
     */
    public function customerVcard(Request $request, string $id)
    {
        $user = $request->attributes->get('authUser');
        if ($user->role !== 'admin' && $user->role !== 'operator') {
            return response()->json(['error' => 'فقط پرسنل به این قابلیت دسترسی دارند.'], 403);
        }

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $name = $order->customer_name ?: ('مشتری '.$order->customer_phone);
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\n"
            .'FN:'.$this->vcardEscape($name)."\r\n"
            .'N:'.$this->vcardEscape($name).";;;;\r\n"
            .'TEL;TYPE=CELL:'.$order->customer_phone."\r\n"
            .'NOTE:'.$this->vcardEscape('سفارش '.$order->order_number.' — کافی‌نت احسان')."\r\n"
            ."END:VCARD\r\n";

        $filename = 'caffenet-contact-'.preg_replace('/[^A-Za-z0-9]+/', '-', (string) $order->order_number).'.vcf';

        return response($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Escape a text value for a vCard 3.0 property (RFC 6350 §3.4). */
    private function vcardEscape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r", "\n"],
            ['\\\\', '\\;', '\\,', '', '\\n'],
            $value
        );
    }

    /** Only unpaid (pending_payment/failed/cancelled) orders are deletable. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد'], 404);
        }

        $deletable = [
            Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED, Order::STATUS_CANCELLED,
        ];
        if (! in_array($order->status, $deletable, true)) {
            return response()->json(['error' => 'فقط سفارش‌های پرداخت‌نشده قابل حذف هستند.'], 400);
        }

        DB::transaction(function () use ($order) {
            DB::table('order_logs')->where('order_id', $order->id)->delete();
            DB::table('ticket_messages')->where('order_id', $order->id)->delete();
            DB::table('payments')->where('order_id', $order->id)->delete();
            // Smart queue cleanup: drop queue_entry, history, notifications.
            DB::table('queue_entries')->where('order_id', $order->id)->delete();
            DB::table('order_status_histories')->where('order_id', $order->id)->delete();
            DB::table('notification_logs')->where('order_id', $order->id)->delete();
            DB::table('orders')->where('id', $order->id)->delete();
        });

        return response()->json(['message' => 'سفارش حذف شد.']);
    }

    /**
     * Coupon evaluation at order creation/validate time: active, not expired,
     * min order met, usage cap not reached. Discount = min(percent × total, max).
     */
    public static function evaluateCoupon(string $code, int $totalBeforeDiscount): array
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();
        if (! $coupon || ! $coupon->is_active) {
            return ['valid' => false, 'error' => 'کد تخفیف معتبر نیست.'];
        }
        if ($coupon->expire_date < now()->toDateString()) {
            return ['valid' => false, 'error' => 'کد تخفیف منقضی شده است.'];
        }
        if ($coupon->max_usage > 0 && $coupon->usage_count >= $coupon->max_usage) {
            return ['valid' => false, 'error' => 'ظرفیت استفاده از کد تخفیف پر شده است.'];
        }
        if ($coupon->min_order_amount > 0 && $totalBeforeDiscount < $coupon->min_order_amount) {
            return ['valid' => false, 'error' => 'مبلغ سفارش برای این کد تخفیف کافی نیست.'];
        }

        $discount = (int) round($totalBeforeDiscount * $coupon->percent / 100);
        if ($coupon->max_amount > 0) {
            $discount = min($discount, $coupon->max_amount);
        }
        $discount = min($discount, $totalBeforeDiscount);

        return ['valid' => true, 'discount' => $discount, 'coupon' => $coupon];
    }

    /** Order number in the original format: CN-YYMM-XXXX. */
    private static function generateOrderNumber(): string
    {
        do {
            $number = 'CN-'.now()->format('ym').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    /** Random work duration (minutes) for a request-mode order, drawn from
     * the admin-configured [min, max] window — see
     * SettingsService::requestOrderDurationRange(). */
    private static function drawRequestDurationMinutes(): int
    {
        [$min, $max] = SettingsService::requestOrderDurationRange();

        return random_int($min, $max);
    }

    /** Keeps only known contact-channel keys, de-duplicated and ordered. */
    private static function sanitizeContactMethods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $allowed = array_keys(Order::CONTACT_METHODS);
        $out = [];
        foreach ($methods as $method) {
            $method = (string) $method;
            if (in_array($method, $allowed, true) && ! in_array($method, $out, true)) {
                $out[] = $method;
            }
        }

        return $out;
    }

    /** Keeps only the safe fields of client-sent uploaded file metadata. */
    private static function sanitizeFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            if (! is_array($f) || empty($f['url'])) {
                continue;
            }
            $out[] = [
                'id' => (string) ($f['id'] ?? Str::uuid()),
                'name' => (string) ($f['name'] ?? ''),
                'size' => (int) ($f['size'] ?? 0),
                'type' => (string) ($f['type'] ?? ''),
                'url' => (string) $f['url'],
                'uploadedAt' => (string) ($f['uploadedAt'] ?? now()->toIso8601String()),
            ];
        }

        return $out;
    }
}
