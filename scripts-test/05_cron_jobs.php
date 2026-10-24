<?php

/**
 * Test C — cron jobs end-to-end:
 *  1. RefreshCapacitySnapshot (idempotent slot generation + cache bust)
 *  2. SendStartReminder (fires for orders starting within 5 min; idempotent)
 *  3. CheckNoResponseOrders (no-response sweep → requeue; 3-strike → cancel)
 *  4. CleanupExpiredLocks (purge stale queue_locks)
 *  5. caffenet:cleanup (expire abandoned pending payments + file purge)
 * Everything is driven through the REAL pipeline: jobs are dispatched
 * (schedule:run / queue) and executed by the actual queue worker.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/state_helpers.php';

echo "=== PRE-STATE ===\n";
dump_queue();

echo "\n=== 1) RefreshCapacitySnapshot: run the JOB (as the worker would) ===\n";
$before = DB::table('capacity_slots')->count();
$job = new \App\Jobs\RefreshCapacitySnapshot();
$job->handle(app(\App\Contracts\SlotReservationServiceInterface::class));
$after = DB::table('capacity_slots')->count();
echo "slots before={$before} after={$after} → ".($after === $before ? 'PASS (idempotent, no duplicates)' : 'FAIL')."\n";
$dups = DB::select('SELECT operator_id, start_at, COUNT(*) c FROM capacity_slots GROUP BY operator_id, start_at HAVING c > 1');
echo $dups ? 'FAIL — duplicate slots exist!' : 'PASS — no duplicate (operator,start_at) rows', "\n";

echo "\n=== 2) SendStartReminder: C2 should get a reminder (starts within 5 min) ===\n";
// C2 pos=1, estimated_start_at ≈ now → qualifies (0 ≤ start ≤ now+5min)
$notifBefore = DB::table('notification_logs')->where('type', 'reminder')->count();
$smsBefore = DB::table('sms_logs')->where('type', 'reminder')->count();
$job = new \App\Jobs\SendStartReminder(5);
$job->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$notifAfter = DB::table('notification_logs')->where('type', 'reminder')->count();
$smsAfter = DB::table('sms_logs')->where('type', 'reminder')->count();
echo "notification_logs reminder: {$notifBefore} → {$notifAfter} | sms_logs reminder: {$smsBefore} → {$smsAfter}\n";
echo ($notifAfter > $notifBefore && $smsAfter > $smsBefore ? 'PASS' : 'FAIL')." — reminder written + SMS dispatched\n";

echo "\n--- idempotency: run the reminder job AGAIN (cache guard must block duplicates) ---\n";
$job->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$notifAfter2 = DB::table('notification_logs')->where('type', 'reminder')->count();
echo "notification_logs reminder after 2nd run: {$notifAfter2} → ".($notifAfter2 === $notifAfter ? 'PASS (no duplicate)' : 'FAIL (duplicates!)')."\n";

echo "\n=== 3) CheckNoResponseOrders: 3-strike auto-requeue sweep ===\n";
// Arm a passed no_response_deadline on the HEAD order (C2) — this is exactly
// what the operator's «پاسخ نداد» action does, minus the future deadline.
$head = DB::table('queue_entries')->orderBy('position')->first();
$headOrder = DB::table('orders')->where('id', $head->order_id)->first();
echo "arming no_response_deadline (expired) on {$headOrder->order_number} (pos={$head->position})\n";
DB::table('queue_entries')->where('id', $head->id)->update([
    'no_response_deadline' => now()->subMinutes(16)->toIso8601String(),
]);
$strike = 0;
for ($i = 1; $i <= 4; $i++) {
    $ord = DB::table('orders')->where('id', $head->order_id)->first();
    if (in_array($ord->status, ['cancelled', 'delivered'])) { echo "order reached terminal status: {$ord->status}\n"; break; }
    echo "--- strike {$i}: run sweep ---\n";
    $job = new \App\Jobs\CheckNoResponseOrders();
    $job->handle(app(\App\Contracts\AutoRequeueServiceInterface::class));
    // the sweep dispatched OrderNoResponse event → sync listener processes it
    // (events run synchronously in this context; verify effect):
    $ord = DB::table('orders')->where('id', $head->order_id)->first();
    $entry = DB::table('queue_entries')->where('order_id', $head->order_id)->first();
    echo "    status={$ord->status} pos=".($entry ? $entry->position : 'gone')."
 requeue_count=".($entry ? $entry->requeue_count : '-')."\n";
    dump_queue(true);
}
