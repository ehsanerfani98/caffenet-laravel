<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes a user together with every row that belongs to them, so no
 * orphaned data is left behind:
 *
 *  - orders where they are the customer, plus those orders' logs, ticket
 *    messages and gateway payments;
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
        DB::transaction(function () use ($user) {
            $orderIds = DB::table('orders')->where('customer_id', $user->id)->pluck('id')->all();

            if (! empty($orderIds)) {
                DB::table('order_logs')->whereIn('order_id', $orderIds)->delete();
                DB::table('ticket_messages')->whereIn('order_id', $orderIds)->delete();
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
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
    }
}
