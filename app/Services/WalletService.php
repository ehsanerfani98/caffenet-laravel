<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of every wallet balance mutation.
 *
 * Each credit/debit runs inside a transaction, re-reads the balance with a row
 * lock, applies the delta, persists the new balance and writes one ledger row
 * carrying the resulting `balance_after` snapshot — so the balance and its
 * history can never drift apart. Callers may invoke these methods inside an
 * existing transaction (Laravel nests them via savepoints).
 */
class WalletService
{
    /**
     * @param  array{orderId?:?string,paymentId?:?string,reference?:?string,note?:?string,performedById?:?string,performedByName?:?string,metadata?:array}  $opts
     */
    public static function credit(User $user, int $amount, string $type, array $opts = []): WalletTransaction
    {
        return self::apply($user, $amount, $type, WalletTransaction::DIRECTION_CREDIT, $opts);
    }

    /**
     * @param  array{orderId?:?string,paymentId?:?string,reference?:?string,note?:?string,performedById?:?string,performedByName?:?string,metadata?:array}  $opts
     *
     * @throws \RuntimeException INSUFFICIENT_WALLET_BALANCE when the wallet cannot cover the amount.
     */
    public static function debit(User $user, int $amount, string $type, array $opts = []): WalletTransaction
    {
        return self::apply($user, $amount, $type, WalletTransaction::DIRECTION_DEBIT, $opts);
    }

    /**
     * Refund to a customer's wallet (credit). When an order is linked it is
     * moved to `refunded`, its `refund_amount`/`refunded_at` are updated and an
     * order-log line is appended so the refund is visible on the order timeline.
     *
     * @param  array{orderId?:?string,paymentId?:?string,reference?:?string,note?:?string,performedById?:?string,performedByName?:?string,metadata?:array}  $opts
     */
    public static function refund(User $user, int $amount, array $opts = []): WalletTransaction
    {
        $orderId = $opts['orderId'] ?? null;
        $order = $orderId ? Order::find($orderId) : null;

        return DB::transaction(function () use ($user, $amount, $opts, $order) {
            $txn = self::credit($user, $amount, WalletTransaction::TYPE_REFUND, $opts);

            if ($order) {
                $now = now()->toIso8601String();
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => Order::STATUS_REFUNDED,
                    'refund_amount' => (int) $order->refund_amount + $amount,
                    'refunded_at' => $now,
                    'updated_at' => $now,
                ]);

                OrderLog::create([
                    'order_id' => $order->id,
                    'action' => 'عودت وجه به کیف پول مشتری: '.number_format($amount).' تومان',
                    'performed_by' => $opts['performedByName'] ?? 'مدیریت',
                    'note' => $opts['note'] ?? null,
                    'timestamp' => $now,
                ]);
            }

            return $txn;
        });
    }

    /**
     * @param  array{orderId?:?string,paymentId?:?string,reference?:?string,note?:?string,performedById?:?string,performedByName?:?string,metadata?:array}  $opts
     */
    private static function apply(User $user, int $amount, string $type, string $direction, array $opts): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('WALLET_AMOUNT_INVALID');
        }

        return DB::transaction(function () use ($user, $amount, $type, $direction, $opts) {
            $row = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! $row) {
                throw new \RuntimeException('WALLET_USER_NOT_FOUND');
            }

            $current = (int) $row->wallet_balance;
            $delta = $direction === WalletTransaction::DIRECTION_CREDIT ? $amount : -$amount;
            $new = $current + $delta;

            if ($new < 0) {
                throw new \RuntimeException('INSUFFICIENT_WALLET_BALANCE');
            }

            DB::table('users')->where('id', $user->id)->update(['wallet_balance' => $new]);

            return WalletTransaction::create([
                'user_id' => $user->id,
                'order_id' => $opts['orderId'] ?? null,
                'payment_id' => $opts['paymentId'] ?? null,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $new,
                'reference' => $opts['reference'] ?? null,
                'note' => $opts['note'] ?? null,
                'performed_by_id' => $opts['performedById'] ?? null,
                'performed_by_name' => $opts['performedByName'] ?? null,
                'metadata_json' => isset($opts['metadata']) ? json_encode($opts['metadata'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now()->toIso8601String(),
            ]);
        });
    }
}
