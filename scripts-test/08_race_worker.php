<?php

/**
 * Test F — concurrent enqueue race: 8 orders enqueued in parallel processes
 * through the REAL QueueManagerService (with its queue:position lock).
 * Verifies positions stay contiguous with no duplicates under contention.
 * Each worker script enqueues one request-mode order and exits.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/state_helpers.php';

$who = $argv[1] ?? 'x';
$cust = DB::table('users')->where('phone', '09122222222')->first();
$oid = 'ord-race-'.getmypid().'-'.substr(md5(uniqid()), 0, 6);

DB::table('orders')->insert([
    'id' => $oid, 'order_number' => 'CN-RACE-'.strtoupper(substr($oid, -6)),
    'customer_id' => $cust->id, 'customer_name' => 'مشتری سارا', 'customer_phone' => '09122222222',
    'service_id' => 'srv-print-doc', 'service_title' => 'پرینت و تکثیر',
    'form_data_json' => '{}', 'urgency' => 'normal', 'delivery_method' => 'download',
    'contact_methods_json' => '["phone"]', 'total_price' => 0, 'discount_amount' => 0, 'paid_amount' => 0,
    'customer_files_json' => '[]', 'result_files_json' => '[]',
    'status' => 'submitted', 'payment_method' => 'request', 'queue_type' => 'normal',
    'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
]);

app(\App\Contracts\OrderLifecycleService::class)->onSubmitted(\App\Models\Order::find($oid));

$pos = DB::table('queue_entries')->where('order_id', $oid)->value('position');
echo "worker {$who}: order={$oid} pos={$pos}\n";
