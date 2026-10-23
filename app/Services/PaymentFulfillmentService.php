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

        DB::transaction(function () use ($payment, $refId, $cardPan, $paymentMethod, $performedByName, &$applied, &$paidOrder) {
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

        // Order-confirmation SMS (smsPatternPaid pattern) — fired AFTER the
        // transaction commits so a rollback never sends a phantom receipt.
        // This closes the gap where gateway payments (Zarinpal checkout,
        // BazaarPay redirect/direct-pay, Cafe Bazaar IAP) and management
        // manual payments never produced the receipt SMS that the wallet
        // path has always sent.
        if ($applied && $paidOrder !== null) {
            try {
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
