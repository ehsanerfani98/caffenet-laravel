<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SmsService;
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
     * ('online' = Zarinpal checkout, 'bazaarpay' = BazaarPay Direct Pay,
     * 'manual' = registered by management in the admin panel).
     * $performedByName (optional) names the staff member who registered a
     * manual payment; it only affects the order-log attribution.
     */
    public static function apply(Payment $payment, ?string $refId = null, ?string $cardPan = null, string $paymentMethod = 'online', ?string $performedByName = null): bool
    {
        $applied = false;
        $paidOrder = null;
        // Whether this payment is what brings the order INTO the system (a
        // pre-payment order becoming submitted). Carried out of the
        // transaction; used post-commit for the management notification.
        $enteredQueue = false;

        DB::transaction(function () use ($payment, $refId, $cardPan, $paymentMethod, $performedByName, &$applied, &$paidOrder, &$enteredQueue) {
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
                    // Status target: the classic pre-payment states advance to
                    // `submitted` (and enter the queue). A request-mode order
                    // («حالت ثبت درخواست») is ALREADY in the queue — it was
                    // born submitted and may even be processing/ready when
                    // management registers the manual payment — so its
                    // current status is preserved untouched.
                    $targetStatus = in_array($order->status, [Order::STATUS_PENDING_PAYMENT, Order::STATUS_FAILED], true)
                        ? Order::STATUS_SUBMITTED
                        : $order->status;

                    DB::table('orders')->where('id', $order->id)->where('paid_amount', 0)->update([
                        'paid_amount' => (int) $payment->amount,
                        'payment_method' => $paymentMethod,
                        'payment_tracking_code' => $refId ?? '',
                        'status' => $targetStatus,
                        'updated_at' => $now,
                    ]);
                    OrderLog::create([
                        'order_id' => $order->id,
                        // Covers both BazaarPay flows (checkout redirect and
                        // silent direct pay) — the recorded method is the same.
                        'action' => match ($paymentMethod) {
                            'bazaarpay' => 'پرداخت با درگاه بازارپی با موفقیت انجام شد',
                            'manual' => 'ثبت پرداخت دستی توسط مدیریت',
                            default => 'پرداخت آنلاین با موفقیت انجام شد',
                        },
                        'performed_by' => $performedByName ?? $order->customer_name,
                        'timestamp' => $now,
                    ]);
                    // Consume the reserved coupon exactly once.
                    if ($order->coupon_id) {
                        DB::table('coupons')->where('id', $order->coupon_id)->increment('usage_count');
                    }

                    // Manual registration: record a wallet-ledger transaction
                    // for the customer so the payment is visible in their
                    // wallet history and the admin's user-transactions roster
                    // — the same record an online/wallet payment produces.
                    // The balance itself is NOT touched (the money was
                    // collected outside the wallet: cash, terminal, transfer),
                    // so this is a pure record row: direction=debit with
                    // balance_after = the untouched current balance.
                    if ($paymentMethod === 'manual' && $payment->user_id) {
                        $owner = DB::table('users')->where('id', $payment->user_id)->first();
                        if ($owner) {
                            WalletTransaction::create([
                                'user_id' => $payment->user_id,
                                'order_id' => $payment->order_id,
                                'payment_id' => $payment->id,
                                'type' => WalletTransaction::TYPE_MANUAL_PAYMENT,
                                'direction' => WalletTransaction::DIRECTION_DEBIT,
                                'amount' => (int) $payment->amount,
                                'balance_after' => (int) $owner->wallet_balance,
                                'reference' => $refId,
                                'note' => 'پرداخت دستی سفارش '.$order->order_number.' توسط مدیریت',
                                'performed_by_name' => $performedByName ?? 'مدیریت',
                                'created_at' => $now,
                            ]);
                        }
                    }

                    $paidOrder = $order;

                    // ── Smart queue: enqueue the now-submitted order ──
                    // Only when this payment is what brought the order INTO
                    // the queue. A request-mode order was enqueued at
                    // submission (onSubmitted's own guard would also catch
                    // it, but skipping here keeps intent explicit).
                    if ($targetStatus !== $order->status) {
                        $enteredQueue = true;
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

        // Order-confirmation SMS (smsPatternPaid pattern) — fired AFTER the
        // transaction commits so a rollback never sends a phantom receipt.
        // This closes the gap where gateway payments (Zarinpal checkout,
        // BazaarPay redirect/direct-pay, Cafe Bazaar IAP) and management
        // manual payments never produced the receipt SMS that the wallet
        // path has always sent.
        if ($applied && $paidOrder !== null) {
            try {
                // Re-read the paid row: the in-transaction model still carries
                // paid_amount=0. (The enqueue used to refresh it implicitly;
                // request-mode orders skip the enqueue, so make it explicit.)
                $paidOrder = $paidOrder->fresh() ?? $paidOrder;
                SmsService::sendOrderNotification(
                    $paidOrder->customer_phone,
                    'paid',
                    [
                        'order_number'   => $paidOrder->order_number,
                        'customer_name'  => $paidOrder->customer_name,
                        'amount'         => (string) $paidOrder->paid_amount,
                        'payment_method' => $paidOrder->payment_method,
                    ]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('PaymentFulfillment: paid SMS failed', [
                    'order_id' => $paidOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Management notification: this payment just brought a NEW order
            // into the system (gateway / manual fulfillment). Request-mode
            // orders were already announced at registration (enteredQueue =
            // false) — never notified twice. Self-contained and never throws.
            if ($enteredQueue) {
                SmsService::sendNewOrderAdminNotification($paidOrder->order_number);
            }
        }

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
