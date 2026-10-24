<?php

/**
 * Test D — re-run cron jobs AFTER the listener-duplication fix:
 *  1. SendStartReminder (with corrected stored estimate) + idempotency
 *  2. No-response 3-strike cycle (re-arming deadline like the real flow)
 *  3. CleanupExpiredLocks
 *  4. caffenet:cleanup (abandoned payment expiry)
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/state_helpers.php';

echo "=== 1) SendStartReminder (head order, stored start within 5 min) ===\n";
// The head order's STORED estimated_start_at is stale (set at enqueue time);
// the reminder job reads the stored column, so simulate the natural passage
// of time: bring stored start to now+3min for the head order.
$head = DB::table('queue_entries')->orderBy('position')->first();
$headOrder = DB::table('orders')->where('id', $head->order_id)->first();
echo "head order {$headOrder->order_number} stored_start={$headOrder->estimated_start_at}\n";
DB::table('orders')->where('id', $head->order_id)->update([
    'estimated_start_at' => now()->addMinutes(3)->toIso8601String(),
]);
$smsB = DB::table('sms_logs')->where('type', 'reminder')->count();
$job = new \App\Jobs\SendStartReminder(5);
$job->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$smsA = DB::table('sms_logs')->where('type', 'reminder')->count();
echo "reminder SMS: {$smsB} → {$smsA}  ".($smsA === $smsB + 1 ? 'PASS (exactly 1)' : 'FAIL')."\n";
$job->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$smsA2 = DB::table('sms_logs')->where('type', 'reminder')->count();
echo "2nd run → {$smsA2}  ".($smsA2 === $smsA ? 'PASS (idempotent)' : 'FAIL (duplicate)')."\n";

echo "\n=== 2) No-response 3-strike cycle (fixed listeners) ===\n";
$head = DB::table('queue_entries')->orderBy('position')->first();
$oid = $head->order_id;
$o = DB::table('orders')->where('id', $oid)->first();
echo "subject: {$o->order_number} pos={$head->position}\n";

$sweepJob = new \App\Jobs\CheckNoResponseOrders();
for ($strike = 1; $strike <= 4; $strike++) {
    $cur = DB::table('orders')->where('id', $oid)->first();
    if (in_array($cur->status, ['cancelled'])) {
        echo "  already terminal → stop\n";
        break;
    }
    // Operator pressed «پاسخ نداد» → deadline armed; 15 minutes pass.
    DB::table('queue_entries')->where('order_id', $oid)->update([
        'no_response_deadline' => now()->subMinutes(16)->toIso8601String(),
    ]);
    $sweepJob->handle(app(\App\Contracts\AutoRequeueServiceInterface::class));

    $entry = DB::table('queue_entries')->where('order_id', $oid)->first();
    $cur = DB::table('orders')->where('id', $oid)->first();
    echo sprintf("  strike %d → status=%s pos=%s requeues=%s\n",
        $strike, $cur->status, $entry ? $entry->position : 'DEQUEUED',
        $entry ? $entry->requeue_count : '-');
    dump_queue(true);
}
$requeuedSms = DB::table('sms_logs')->where('type', 'requeued')->count();
$cancelledSms = DB::table('sms_logs')->where('type', 'cancelled')->count();
echo "SMS requeued={$requeuedSms} (expect 3) | cancelled={$cancelledSms} (expect 1)\n";
echo ((int) $requeuedSms === 3 && (int) $cancelledSms === 1 ? 'PASS — 3-strike rule exact' : 'FAIL — strike accounting wrong')."\n";

echo "\n=== 3) CleanupExpiredLocks ===\n";
DB::table('queue_locks')->insert([
    ['key' => 'test:lock-expired', 'owner' => 'ghost-1', 'expires_at' => now()->subMinutes(10)->toIso8601String()],
    ['key' => 'test:lock-live', 'owner' => 'ghost-2', 'expires_at' => now()->addMinutes(10)->toIso8601String()],
]);
$locksJob = new \App\Jobs\CleanupExpiredLocks();
$locksJob->handle(app(\App\Contracts\QueueLockServiceInterface::class));
$left = DB::table('queue_locks')->where('key', 'like', 'test:%')->pluck('key')->all();
echo "remaining test locks: ".json_encode($left)." → ".($left === ['test:lock-live'] ? 'PASS (expired purged, live kept)' : 'FAIL')."\n";

echo "\n=== 4) caffenet:cleanup — abandoned pending_payment expiry (>30min) ===\n";
// Make C3's active order look abandoned: pending payment, old.
$abandoned = DB::table('orders')->where('status', 'pending_payment')->first();
if (! $abandoned) {
    // C3 already has a submitted order; create a fresh abandoned one directly.
    $cust = DB::table('users')->where('phone', '09133333333')->first();
    $abandonedId = 'ord-abandoned-'.substr((string) uniqid(), -6);
    DB::table('orders')->insert([
        'id' => $abandonedId, 'order_number' => 'CN-TEST-ABANDON',
        'customer_id' => $cust->id, 'customer_name' => 'مشتری رضا', 'customer_phone' => '09133333333',
        'service_id' => 'srv-print-doc', 'service_title' => 'پرینت و تکثیر',
        'form_data' => '{}', 'urgency' => 'normal', 'delivery_method' => 'download',
        'contact_methods' => '["phone"]', 'total_price' => 15000, 'discount_amount' => 0, 'paid_amount' => 0,
        'status' => 'pending_payment', 'payment_method' => 'wallet', 'queue_type' => 'long',
        'created_at' => now()->subMinutes(45)->toIso8601String(), 'updated_at' => now()->subMinutes(45)->toIso8601String(),
    ]);
    DB::table('payments')->insert([
        'id' => 'pay-abandoned-1', 'order_id' => $abandonedId, 'user_id' => $cust->id,
        'purpose' => 'order', 'amount' => 15000, 'status' => 'pending', 'method' => 'online',
        'created_at' => now()->subMinutes(45)->toIso8601String(), 'updated_at' => now()->subMinutes(45)->toIso8601String(),
    ]);
    $abandoned = DB::table('orders')->where('id', $abandonedId)->first();
}
echo "abandoned order {$abandoned->order_number} status={$abandoned->status} created=".substr($abandoned->created_at, 11, 5)."\n";
\Artisan::call('caffenet:cleanup');
echo trim(\Artisan::output())."\n";
$after = DB::table('orders')->where('id', $abandoned->id)->value('status');
$payStatus = DB::table('payments')->where('order_id', $abandoned->id)->value('status');
echo "order status={$after} payment={$payStatus} → ".($after === 'failed' && $payStatus === 'failed' ? 'PASS' : 'FAIL')."\n";
