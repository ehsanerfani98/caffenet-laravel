<?php

namespace App\Services;

use App\Contracts\QueueManagerServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes a user together with every row that belongs to them, so no
 * orphaned data is left behind:
 *
 *  - orders where they are the customer, plus those orders' logs, ticket
 *    messages, gateway payments and queue entries (deleting the orders while
 *    leaving their queue_entries rows behind used to poison the queue: the
 *    ghosts kept feeding enqueue's max(position)+1, so a brand-new customer
 *    could inherit a phantom «3 نفرهای جلوی شما» position on an empty queue);
 *  - wallet top-up payments (not tied to an order) and the wallet ledger;
 *  - phone-bound authentication artefacts (OTP codes) and SMS logs.
 *
 * Orders the user was handling as an operator/admin belong to other customers,
 * so they are NOT deleted — only unassigned.
 */
class UserPurgeService
{
    public static function purge(User $user): void
    {
        $removedOrders = false;

        DB::transaction(function () use ($user, &$removedOrders) {
            $orderIds = DB::table('orders')->where('customer_id', $user->id)->pluck('id')->all();
            $removedOrders = ! empty($orderIds);

            if (! empty($orderIds)) {
                DB::table('order_logs')->whereIn('order_id', $orderIds)->delete();
                DB::table('ticket_messages')->whereIn('order_id', $orderIds)->delete();
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                // queue_entries has no FK cascade — clean it explicitly.
                DB::table('queue_entries')->whereIn('order_id', $orderIds)->delete();
                DB::table('orders')->whereIn('id', $orderIds)->delete();
            }

            // Wallet top-ups (order_id null) and the wallet ledger.
            DB::table('payments')->where('user_id', $user->id)->delete();
            DB::table('wallet_transactions')->where('user_id', $user->id)->delete();

            // Phone-bound auth/notification artefacts.
            DB::table('otp_codes')->where('phone', $user->phone)->delete();
            DB::table('sms_logs')->where('phone', $user->phone)->delete();

            // Keep the customers' orders intact if this account was staff.
            DB::table('orders')->where('assigned_operator_id', $user->id)->update([
                'assigned_operator_id' => null,
                'assigned_operator_name' => null,
            ]);

            // Detach spatie pivots, then drop the account.
            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();
        });

        // Deleting queued orders leaves position gaps behind, and the tracker
        // displays «ahead = position - 1». compact() drops any leftover ghosts
        // and repacks the surviving entries contiguously so no other customer
        // keeps a phantom position after the purge.
        if ($removedOrders) {
            app(QueueManagerServiceInterface::class)->compact();
        }
    }
}
