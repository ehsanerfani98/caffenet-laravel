<?php

/**
 * Test E — CleanupExpiredLocks (correct schema) + full cron pipeline:
 *   schedule:run (dispatches queued jobs) → queue:work --stop-when-empty
 * This mirrors the cPanel cron exactly (cron → schedule:run → queue worker).
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/state_helpers.php';

echo "=== 1) CleanupExpiredLocks (correct columns) ===\n";
DB::table('queue_locks')->insert([
    ['id' => 'lk-exp-1', 'resource_key' => 'test:lock-expired', 'owner_token' => 'ghost-1',
     'ttl_seconds' => 15, 'locked_at' => now()->subMinutes(10)->toIso8601String(), 'expires_at' => now()->subMinutes(9)->toIso8601String()],
    ['id' => 'lk-live-1', 'resource_key' => 'test:lock-live', 'owner_token' => 'ghost-2',
     'ttl_seconds' => 900, 'locked_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(10)->toIso8601String()],
]);
$job = new \App\Jobs\CleanupExpiredLocks();
$job->handle(app(\App\Contracts\QueueLockServiceInterface::class));
$left = DB::table('queue_locks')->whereIn('id', ['lk-exp-1', 'lk-live-1'])->pluck('id')->all();
echo "remaining: ".json_encode($left)." → ".($left === ['lk-live-1'] ? 'PASS (expired purged, live kept)' : 'FAIL')."\n";
// cleanup our fixture
DB::table('queue_locks')->where('id', 'lk-live-1')->delete();

echo "\n=== 2) Full cron pipeline: schedule:run → queue:work ===\n";
echo "orders in queue before: ".DB::table('queue_entries')->count()."\n";
// Fresh customer + order for the pipeline test (request-mode → born submitted)
$cust = DB::table('users')->where('phone', '09122222222')->first();
DB::table('orders')->insert([
    'id' => 'ord-pipeline-1', 'order_number' => 'CN-PIPE-0001',
    'customer_id' => $cust->id, 'customer_name' => 'مشتری سارا', 'customer_phone' => '09122222222',
    'service_id' => 'srv-print-doc', 'service_title' => 'پرینت و تکثیر',
    'form_data_json' => '{}', 'urgency' => 'normal', 'delivery_method' => 'download',
    'contact_methods_json' => '["phone"]', 'total_price' => 0, 'discount_amount' => 0, 'paid_amount' => 0,
    'customer_files_json' => '[]', 'result_files_json' => '[]',
    'status' => 'submitted', 'payment_method' => 'request', 'queue_type' => 'normal',
    'estimated_duration_minutes' => 20,
    'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
]);
app(\App\Contracts\OrderLifecycleService::class)->onSubmitted(
    \App\Models\Order::find('ord-pipeline-1')
);
echo "pipeline order submitted → pos=".DB::table('queue_entries')->where('order_id','ord-pipeline-1')->value('position')."\n";

echo "\n--- php artisan schedule:run (cron equivalent, one minute tick) ---\n";
passthru('/home/z/tools/php84/php artisan schedule:run 2>&1', $rc);
echo "schedule:run exit={$rc}\n";
echo "jobs table rows waiting: ".DB::table('jobs')->count()."\n";

echo "\n--- php artisan queue:work --stop-when-empty --max-time=50 ---\n";
passthru('/home/z/tools/php84/php artisan queue:work --stop-when-empty --max-time=50 --tries=1 -n 2>&1 | tail -8', $rc2);
echo "queue:work exit={$rc2}\n";
echo "jobs remaining: ".DB::table('jobs')->count()." | failed_jobs: ".DB::table('failed_jobs')->count()."\n";
dump_queue(true);
echo "\ncapacity_slots total: ".DB::table('capacity_slots')->count()."\n";
