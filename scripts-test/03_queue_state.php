<?php

/**
 * Test B-1 — dump queue state, verify positions/estimates from DB,
 * and show the SMS log + laravel.log SMS lines collected so far.
 */

use Illuminate\Support\Facades\DB;

echo "=== QUEUE STATE (queue_entries) ===\n";
$entries = DB::table('queue_entries')->orderBy('position')->get();
foreach ($entries as $e) {
    $o = DB::table('orders')->where('id', $e->order_id)->first();
    echo sprintf(
        "pos=%d order=%s [%s] status=%s dur=%smin wait=%smin entered=%s started=%s\n",
        $e->position, $o->order_number, substr($o->customer_name, 0, 20), $o->status,
        $o->estimated_duration_minutes, $o->estimated_wait_minutes,
        substr($e->entered_at, 11, 8), $e->started_at ? substr($e->started_at, 11, 8) : '-'
    );
}

echo "\n=== CONTIGUITY CHECK ===\n";
$positions = $entries->pluck('position')->sort()->values()->toArray();
$expected = range(1, count($positions));
echo (implode(',', $positions) === implode(',', $expected))
    ? "PASS — positions are contiguous 1..".count($positions)."\n"
    : "FAIL — positions: ".implode(',', $positions)."\n";

echo "\n=== orders.queue_position SYNC CHECK ===\n";
$mismatch = 0;
foreach ($entries as $e) {
    $o = DB::table('orders')->where('id', $e->order_id)->value('queue_position');
    if ((int) $o !== (int) $e->position) { $mismatch++; echo "MISMATCH order={$e->order_id} entry={$e->position} order_row={$o}\n"; }
}
echo $mismatch === 0 ? "PASS — all queue_position values match queue_entries\n" : "FAIL — {$mismatch} mismatches\n";

echo "\n=== WAIT MATH VERIFICATION (algorithm: ceil(Σ durations of not-started AHEAD / active operators)) ===\n";
$activeOps = DB::table('operator_profiles')->where('is_active', true)->count();
echo "active operators = {$activeOps}\n";
$running = 0; $sumAhead = 0; $ok = true;
foreach ($entries as $e) {
    $o = DB::table('orders')->where('id', $e->order_id)->first();
    $expectedWait = $sumAhead > 0 ? (int) ceil($sumAhead / $activeOps) : 0;
    $status = ((int) $o->estimated_wait_minutes === $expectedWait) ? 'PASS' : 'FAIL';
    if ($status === 'FAIL') { $ok = false; }
    echo sprintf("%s %s: expected_wait=%d actual_wait=%d (Σahead=%d)\n",
        $status, $o->order_number, $expectedWait, $o->estimated_wait_minutes, $sumAhead);
    $sumAhead += (int) $o->estimated_duration_minutes;
}

echo "\n=== SMS LOGS SO FAR ===\n";
$logs = DB::table('sms_logs')->orderBy('timestamp')->get();
foreach ($logs as $l) {
    echo sprintf("[%s] %s → %s | %s | err=%s\n", substr($l->timestamp, 11, 8), $l->phone, $l->status, $l->type, $l->error ?? '-');
}
echo "total sms_logs rows: ".count($logs)."\n";
