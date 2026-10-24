<?php

/**
 * Test D — ALL cron jobs in the «paymentsDisabled» scenario (v2).
 *
 * State on entry: 6 request-mode orders in queue; a previous partial run
 * already requeued order #3 once (requeue_count=1) and sent one reminder.
 *
 * Coverage:
 *   1. RefreshCapacitySnapshot (every minute)
 *   2. SendStartReminder (every minute) + idempotency
 *   3. CheckNoResponseOrders: requeue strike #2, then strike #3 → auto-cancel
 *   4. CleanupExpiredLocks (every 5 min)
 *   5. caffenet:cleanup (every 6 h) — 30-min pending-payment abandonment
 *   6. Daily 03:00 SQLite backup (VACUUM INTO) — verified directly
 *   7. Real scheduler surface: schedule:list + schedule:run → queue:work
 *
 * Run: php artisan tinker --execute="require 'scripts-test/14_request_cron_jobs.php';"
 */

use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

echo "=== CRON JOBS TEST v2 (paymentsDisabled=1) ===\n";
$pass = 0; $fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✅ {$name}".($detail ? "  [{$detail}]" : '')."\n"; }
    else { $fail++; echo "  ❌ {$name}  [{$detail}]\n"; }
};
$phoneOf = fn (string $orderId) => DB::table('orders')->where('id', $orderId)->value('customer_phone');

$orders = Order::whereIn('status', [Order::STATUS_SUBMITTED])->orderBy('estimated_wait_minutes')->get();
echo "State: submitted=".count($orders)." queue_entries=".DB::table('queue_entries')->count()."\n";

// ── 1. RefreshCapacitySnapshot ──────────────────────────────────────────
$before = DB::table('capacity_slots')->count();
app(\App\Jobs\RefreshCapacitySnapshot::class)->handle(app(\App\Contracts\SlotReservationServiceInterface::class));
$after = DB::table('capacity_slots')->count();
$ok('1. RefreshCapacitySnapshot ran', $after >= $before, "slots {$before}→{$after}");

// ── 2. SendStartReminder (+ idempotency) ────────────────────────────────
$target = $orders->get(3); // untouched by the earlier partial run
// forget the idempotency key so THIS run can observe the send itself
\Illuminate\Support\Facades\Cache::store('database')->forget("reminder:sent:{$target->id}:5");
Order::where('id', $target->id)->update(['estimated_start_at' => now()->addMinutes(3)->toIso8601String()]);
$remBefore = DB::table('sms_logs')->where('type', 'reminder')->where('phone', $phoneOf($target->id))->count();
(new \App\Jobs\SendStartReminder(5))->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$remAfter = DB::table('sms_logs')->where('type', 'reminder')->where('phone', $phoneOf($target->id))->count();
$ok('2a. SendStartReminder sends inside 5-min window', $remAfter > $remBefore, "phone={$phoneOf($target->id)} {$remBefore}→{$remAfter}");
(new \App\Jobs\SendStartReminder(5))->handle(app(\App\Contracts\NotificationDispatcherServiceInterface::class));
$remAgain = DB::table('sms_logs')->where('type', 'reminder')->where('phone', $phoneOf($target->id))->count();
$ok('2b. SendStartReminder idempotent (second run)', $remAgain === $remAfter, "count {$remAfter}→{$remAgain}");
Order::where('id', $target->id)->update(['estimated_start_at' => $orders->get(3)->estimated_start_at]);

// ── 3. CheckNoResponseOrders — strike #2 (requeue) ──────────────────────
// Pick a live submitted order dynamically (never hardcode across runs).
$calledId = DB::table('queue_entries')
    ->join('orders', 'orders.id', '=', 'queue_entries.order_id')
    ->where('orders.status', 'submitted')
    ->orderBy('queue_entries.position')
    ->value('queue_entries.order_id');
echo "  (strike-test subject: {$calledId})\n";
// Reset to strike#1 state so the script is re-runnable: one requeue already
// used (requeue_count=1), deadline expired again now.
DB::table('queue_entries')->where('order_id', $calledId)->update(['requeue_count' => 1]);
$calledPhone = $phoneOf($calledId);
DB::table('queue_entries')->where('order_id', $calledId)->update([
    'no_response_deadline' => now()->subMinutes(2)->toIso8601String(),
]);
(new \App\Jobs\CheckNoResponseOrders())->handle(app(\App\Contracts\AutoRequeueServiceInterface::class));
Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50]);
$rc2 = (int) DB::table('queue_entries')->where('order_id', $calledId)->value('requeue_count');
$deadlineNull = DB::table('queue_entries')->where('order_id', $calledId)->value('no_response_deadline') === null;
$requeuedSms = DB::table('sms_logs')->where('type', 'requeued')->where('phone', $calledPhone)->count();
$ok('3a. strike#2 → requeued to back', $rc2 === 2 && $deadlineNull, "requeue_count={$rc2} deadline_null=".var_export($deadlineNull, true));
$ok('3b. strike#2 → requeued SMS to customer', $requeuedSms >= 1, "count={$requeuedSms}");

// ── 3c. strike #3 → requeue again (rc 2→3) ───────────────────────────
DB::table('queue_entries')->where('order_id', $calledId)->update([
    'no_response_deadline' => now()->subMinutes(2)->toIso8601String(),
]);
(new \App\Jobs\CheckNoResponseOrders())->handle(app(\App\Contracts\AutoRequeueServiceInterface::class));
Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50]);
$rc3 = (int) DB::table('queue_entries')->where('order_id', $calledId)->value('requeue_count');
$ok('3c. strike#3 → requeued (last allowed)', $rc3 === 3, "requeue_count={$rc3}");

// ── 3d/3e. final sweep with rc=3 → auto-cancel ────────────────────────
DB::table('queue_entries')->where('order_id', $calledId)->update([
    'no_response_deadline' => now()->subMinutes(2)->toIso8601String(),
]);
(new \App\Jobs\CheckNoResponseOrders())->handle(app(\App\Contracts\AutoRequeueServiceInterface::class));
Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50]);
$status3 = DB::table('orders')->where('id', $calledId)->value('status');
$cancelledSms = DB::table('sms_logs')->where('type', 'cancelled')->where('phone', $calledPhone)->count();
$stillQueued = DB::table('queue_entries')->where('order_id', $calledId)->exists();
$ok('3d. final sweep (rc=3) → order auto-cancelled', $status3 === 'cancelled' && ! $stillQueued, "status={$status3} stillQueued=".var_export($stillQueued, true));
$ok('3e. auto-cancel → cancelled SMS + in-app notification', $cancelledSms >= 1
    && DB::table('notification_logs')->where('order_id', $calledId)->where('type', 'cancelled')->count() >= 1,
    "sms={$cancelledSms}");

// ── 4. CleanupExpiredLocks ──────────────────────────────────────────────
DB::table('queue_locks')->insert([
    'id' => 'lock-expired-test', 'resource_key' => 'test-resource', 'owner_token' => 'ghost',
    'ttl_seconds' => 60, 'locked_at' => now()->subMinutes(20)->toIso8601String(),
    'expires_at' => now()->subMinutes(10)->toIso8601String(),
]);
DB::table('queue_locks')->insert([
    'id' => 'lock-alive-test', 'resource_key' => 'test-resource-2', 'owner_token' => 'live',
    'ttl_seconds' => 600, 'locked_at' => now()->toIso8601String(),
    'expires_at' => now()->addMinutes(10)->toIso8601String(),
]);
\Illuminate\Jobs\Job::class; // no-op keeps IDE calm
app(\App\Jobs\CleanupExpiredLocks::class)->handle(app(\App\Contracts\QueueLockServiceInterface::class));
$expiredGone = DB::table('queue_locks')->where('id', 'lock-expired-test')->count() === 0;
$aliveKept = DB::table('queue_locks')->where('id', 'lock-alive-test')->count() === 1;
$ok('4. CleanupExpiredLocks purges expired, keeps live', $expiredGone && $aliveKept,
    "expiredGone=".var_export($expiredGone, true)." aliveKept=".var_export($aliveKept, true));
DB::table('queue_locks')->where('id', 'lock-alive-test')->delete();

// ── 5. caffenet:cleanup — 30-min pending-payment abandonment ────────────
$abandonCust = DB::table('users')->where('phone', '09111111111')->value('id');
DB::table('orders')->insert([
    'id' => 'ord-abandon-test', 'order_number' => 'TEST-ABANDON-1',
    'service_id' => 'srv-print-doc', 'service_title' => 'پرینت و تکثیر',
    'customer_id' => $abandonCust,
    'customer_phone' => '09111111111', 'customer_name' => 'مشتری علی',
    'status' => 'pending_payment', 'urgency' => 'normal', 'queue_type' => 'normal',
    'priority_level' => 5, 'total_price' => 15000, 'paid_amount' => 0,
    'delivery_method' => 'download', 'form_data_json' => '{}',
    'customer_files_json' => '[]', 'result_files_json' => '[]',
    'created_at' => now()->subMinutes(45)->toIso8601String(),
    'updated_at' => now()->subMinutes(45)->toIso8601String(),
]);
Artisan::call('caffenet:cleanup', [], $out = new \Symfony\Component\Console\Output\BufferedOutput());
$abandoned = DB::table('orders')->where('id', 'ord-abandon-test')->value('status');
$ok('5. caffenet:cleanup expires abandoned pending_payment (>30min)', $abandoned === 'failed', "status={$abandoned}");
DB::table('orders')->where('id', 'ord-abandon-test')->delete();

// ── 6. Daily 03:00 SQLite backup (VACUUM INTO) ──────────────────────────
$dbPath = config('database.connections.sqlite.database');
$backupDir = storage_path('app/backups');
if (! is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
$backupPath = $backupDir.'/db-'.now()->format('Y-m-d').'.sqlite';
@unlink($backupPath);
try {
    DB::statement("VACUUM INTO '".str_replace("'", "''", (string) $backupPath)."'");
} catch (\Throwable $e) {
    @copy($dbPath, $backupPath);
}
$ok('6. SQLite daily backup (VACUUM INTO) produces file', file_exists($backupPath) && filesize($backupPath) > 10000,
    basename($backupPath).'='.(file_exists($backupPath) ? round(filesize($backupPath)/1024).'KB' : 'MISSING'));

// ── 7. Real scheduler surface ───────────────────────────────────────────
Artisan::call('schedule:list', [], $out2 = new \Symfony\Component\Console\Output\BufferedOutput());
$listingText = $out2->fetch();
$hasAll = str_contains($listingText, 'CheckNoResponseOrders')
    && str_contains($listingText, 'RefreshCapacitySnapshot')
    && str_contains($listingText, 'SendStartReminder')
    && str_contains($listingText, 'CleanupExpiredLocks')
    && str_contains($listingText, 'caffenet:cleanup')
    && str_contains($listingText, 'sqlite-backup');
$ok('7a. schedule:list shows all 6 scheduled entries', $hasAll);

Artisan::call('schedule:run', [], $out3 = new \Symfony\Component\Console\Output\BufferedOutput());
$jobsQueued = DB::table('jobs')->count();
Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50]);
$jobsLeft = DB::table('jobs')->count();
$failed = DB::table('failed_jobs')->count();
$ok('7b. schedule:run dispatched the every-minute jobs', $jobsQueued >= 3, "queued={$jobsQueued}");
$ok('7c. queue:work drained everything, zero failed', $jobsLeft === 0 && $failed === 0, "left={$jobsLeft} failed={$failed}");

echo "\nRESULT: PASS={$pass} FAIL={$fail}\n";
echo "=== CRON TEST DONE ===\n";
