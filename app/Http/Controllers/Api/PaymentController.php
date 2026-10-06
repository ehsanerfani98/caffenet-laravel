<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payments — faithful port of paymentsRouter in routes.ts.
 *
 *  - purpose "order": pays an existing order; amount recomputed from the
 *    record (total_price − discount_amount), never trusted from client.
 *  - purpose "wallet": wallet top-up within MIN/MAX bounds.
 *  - verify is idempotent (codes 100/101, transactional state transitions,
 *    coupon consumed exactly once).
 */
class PaymentController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $body = (array) $request->json()->all();
        $purpose = ($body['purpose'] ?? 'order') === 'wallet' ? 'wallet' : 'order';
        $now = now()->toIso8601String();

        if ($purpose === 'order') {
            $order = Order::find((string) ($body['orderId'] ?? ''));
            if (! $order || $order->customer_id !== $user->id) {
                return response()->json(['error' => 'سفارش یافت نشد یا متعلق به شما نیست.'], 404);
            }
            if (in_array($order->status, [Order::STATUS_SUBMITTED, Order::STATUS_PROCESSING, Order::STATUS_READY, Order::STATUS_DELIVERED], true)) {
                return response()->json(['error' => 'این سفارش قبلاً پرداخت شده است.'], 400);
            }
            if (! in_array($order->status, [Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED], true)) {
                return response()->json(['error' => 'پرداخت مجدد برای این سفارش امکان‌پذیر نیست.'], 400);
            }

            $amount = max(0, (int) $order->total_price - (int) $order->discount_amount);
            if ($amount <= 0) {
                return response()->json(['error' => 'مبلغ قابل پرداخت نامعتبر است.'], 400);
            }
            $description = "پرداخت سفارش {$order->order_number}";
        } else {
            $amount = (int) ($body['amount'] ?? 0);
            if ($amount < PaymentService::MIN_WALLET_TOPUP || $amount > PaymentService::MAX_WALLET_TOPUP) {
                return response()->json(['error' => 'مبلغ شارژ باید بین ۱۰٬۰۰۰ تا ۵۰٬۰۰۰٬۰۰۰ تومان باشد.'], 400);
            }
            $description = 'شارژ کیف پول';
        }

        $gatewayResult = PaymentService::requestPayment(
            $amount,
            $description,
            SettingsService::paymentSettings()['callbackUrl'],
            $user->phone
        );

        if (! $gatewayResult['ok']) {
            // Mark the order failed on gateway errors (per the payment-centric
            // lifecycle: request errors → failed + log).
            if ($purpose === 'order') {
                DB::transaction(function () use ($order, $now) {
                    DB::table('orders')->where('id', $order->id)->where('paid_amount', 0)
                        ->update(['status' => Order::STATUS_FAILED, 'updated_at' => $now]);
                    OrderLog::create([
                        'order_id' => $order->id,
                        'action' => 'پرداخت ناموفق بود',
                        'performed_by' => 'سیستم',
                        'note' => $gatewayResult['error'] ?? 'خطای درگاه',
                        'timestamp' => $now,
                    ]);
                });
            }

            return response()->json(['error' => $gatewayResult['error']], 400);
        }

        $payment = Payment::create([
            'order_id' => $purpose === 'order' ? $order->id : null,
            'user_id' => $user->id,
            'purpose' => $purpose,
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

        // Verified — apply effects exactly once.
        DB::transaction(function () use ($payment, $result) {
            $now = now()->toIso8601String();

            $affected = DB::table('payments')
                ->where('id', $payment->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    'status' => 'paid',
                    'ref_id' => $result['refId'] ?? null,
                    'card_pan' => $result['cardPan'] ?? null,
                    'verified_at' => $now,
                ]);

            if ($affected === 0) {
                return; // already applied by a concurrent callback — idempotent exit
            }

            if ($payment->purpose === 'order') {
                $order = Order::find($payment->order_id);
                if ($order) {
                    DB::table('orders')->where('id', $order->id)->where('paid_amount', 0)->update([
                        'paid_amount' => (int) $payment->amount,
                        'payment_method' => 'online',
                        'payment_tracking_code' => $result['refId'] ?? '',
                        'status' => Order::STATUS_SUBMITTED,
                        'updated_at' => $now,
                    ]);
                    OrderLog::create([
                        'order_id' => $order->id,
                        'action' => 'پرداخت آنلاین با موفقیت انجام شد',
                        'performed_by' => $order->customer_name,
                        'timestamp' => $now,
                    ]);
                    // Consume the reserved coupon exactly once.
                    if ($order->coupon_id) {
                        DB::table('coupons')->where('id', $order->coupon_id)->increment('usage_count');
                    }
                }
            } else { // wallet top-up
                DB::table('users')->where('id', $payment->user_id)->increment('wallet_balance', (int) $payment->amount);
            }
        });

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
}
