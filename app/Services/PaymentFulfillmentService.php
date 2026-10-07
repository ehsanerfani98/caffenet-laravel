<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
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
     */
    public static function apply(Payment $payment, ?string $refId = null, ?string $cardPan = null): bool
    {
        $applied = false;

        DB::transaction(function () use ($payment, $refId, $cardPan, &$applied) {
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
                        'payment_method' => 'online',
                        'payment_tracking_code' => $refId ?? '',
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
