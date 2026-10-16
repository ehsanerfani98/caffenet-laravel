<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Applies the effects of a *verified* payment exactly once.
 *
 * Extracted from PaymentController::verify so both the Zarinpal verify flow and
 * the Cafe Bazaar in-app gateway listener share one transactional, idempotent
 * implementation (guarded UPDATEs make concurrent callbacks a no-op).
 */
class PaymentFulfillmentService
{
    /**
     * Mark the payment paid and advance the order / wallet. Idempotent:
     * returns true only when this call performed the transition.
     *
     * $paymentMethod records HOW the order was paid on the order row
     * ('online' = Zarinpal checkout, 'bazaarpay' = BazaarPay Direct Pay).
     */
    public static function apply(Payment $payment, ?string $refId = null, ?string $cardPan = null, string $paymentMethod = 'online'): bool
    {
        $applied = false;

        DB::transaction(function () use ($payment, $refId, $cardPan, $paymentMethod, &$applied) {
            $now = now()->toIso8601String();

            $affected = DB::table('payments')
                ->where('id', $payment->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    'status' => 'paid',
                    'ref_id' => $refId,
                    'card_pan' => $cardPan,
                    'verified_at' => $now,
                ]);

            if ($affected === 0) {
                return; // already applied by a concurrent callback — idempotent exit
            }

            $applied = true;

            if ($payment->purpose === 'order') {
                $order = Order::find($payment->order_id);
                if ($order) {
                    DB::table('orders')->where('id', $order->id)->where('paid_amount', 0)->update([
                        'paid_amount' => (int) $payment->amount,
                        'payment_method' => $paymentMethod,
                        'payment_tracking_code' => $refId ?? '',
                        'status' => Order::STATUS_SUBMITTED,
                        'updated_at' => $now,
                    ]);
                    OrderLog::create([
                        'order_id' => $order->id,
                        'action' => $paymentMethod === 'bazaarpay'
                            ? 'پرداخت مستقیم با بازارپی با موفقیت انجام شد'
                            : 'پرداخت آنلاین با موفقیت انجام شد',
                        'performed_by' => $order->customer_name,
                        'timestamp' => $now,
                    ]);
                    // Consume the reserved coupon exactly once.
                    if ($order->coupon_id) {
                        DB::table('coupons')->where('id', $order->coupon_id)->increment('usage_count');
                    }

                    // ── Smart queue: enqueue the now-submitted order ──
                    // Resolved out of the container to avoid a static circular ref.
                    try {
                        $order->refresh();
                        app(\App\Services\Queue\OrderLifecycleService::class)->onSubmitted($order);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('PaymentFulfillment: onSubmitted failed', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else { // wallet top-up
                $owner = User::find($payment->user_id);
                if ($owner) {
                    WalletService::credit($owner, (int) $payment->amount, WalletTransaction::TYPE_TOPUP, [
                        'paymentId' => $payment->id,
                        'reference' => $refId,
                        'note' => 'شارژ کیف پول از درگاه',
                        'performedByName' => 'درگاه پرداخت',
                        'metadata' => ['gateway' => $payment->gateway],
                    ]);
                }
            }
        });

        return $applied;
    }

    /**
     * Mark a pending payment (and its order) as failed. Safe to call for a
     * payment that was already resolved — it simply does nothing then.
     */
    public static function markFailed(Payment $payment, ?string $note = null): void
    {
        DB::transaction(function () use ($payment, $note) {
            $now = now()->toIso8601String();

            $affected = DB::table('payments')
                ->where('id', $payment->id)
                ->where('status', 'pending')
                ->update(['status' => 'failed']);

            if ($affected === 0) {
                return;
            }

            if ($payment->order_id) {
                $order = Order::find($payment->order_id);
                if ($order && $order->status === Order::STATUS_PENDING_PAYMENT) {
                    DB::table('orders')->where('id', $order->id)
                        ->update(['status' => Order::STATUS_FAILED, 'updated_at' => $now]);
                    OrderLog::create([
                        'order_id' => $order->id,
                        'action' => 'پرداخت ناموفق بود',
                        'performed_by' => 'درگاه پرداخت',
                        'note' => $note,
                        'timestamp' => $now,
                    ]);
                }
            }
        });
    }
}
