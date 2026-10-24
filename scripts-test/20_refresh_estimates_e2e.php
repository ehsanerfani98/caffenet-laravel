<?php

/**
 * End-to-end pipeline check for RefreshQueueEstimates:
 * real schedule:run dispatch → real queue:work → stamps actually re-written.
 *
 * Scenario (the user's report): A is in progress and LONG over-running,
 * B waits behind it with enqueue-time fossil stamps. After one scheduler
 * pass B must read "start = now", and A's own end estimate must be intact.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\QueueEntry;
use App\Services\Queue\QueueManagerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── setup (order_types already seeded by OrderTypeSeeder) ───────────────
DB::table('operator_profiles')->insert([
    'id' => 'oprofile-e2e', 'user_id' => 'usr-e2e-op', 'display_name' => 'Op',
    'capacity_per_hour' => 2, 'is_active' => true,
    'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
]);

function makeOrder(string $status = Order::STATUS_SUBMITTED): Order
{
    $o = new Order();
    $o->id = 'ord-'.Str::uuid();
    $o->order_number = 'CN-'.random_int(1000, 9999);
    $o->service_id = 'svc-e2e';
    $o->service_title = 'E2E Service';
    $o->customer_id = 'usr-e2e';
    $o->customer_phone = '09120000000';
    $o->customer_name = 'E2E';
    $o->status = $status;
    $o->urgency = 'normal';
    $o->queue_type = 'normal';
    $o->priority_level = 5;
    $o->form_data_json = '[]';
    $o->customer_files_json = '[]';
    $o->result_files_json = '[]';
    $o->total_price = 1000;
    $o->paid_amount = 1000;
    $o->payment_method = 'wallet';
    $o->payment_tracking_code = '';
    $o->created_at = now()->toIso8601String();
    $o->updated_at = now()->toIso8601String();
    $o->save();

    return $o;
}

$queue = app(QueueManagerService::class);

// A: started 60 min ago with a 20-min job → predicted end = 40 min ago.
$a = makeOrder();
$a->estimated_duration_minutes = 20;
$a->save();
$queue->enqueue($a);
$queue->startOrder($a);
QueueEntry::where('order_id', $a->id)->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
$a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
$a->save();

// B: waiting behind A with the enqueue-time fossil (start promised 40 min ago).
$b = makeOrder();
$b->estimated_duration_minutes = 30;
$b->save();
$queue->enqueue($b);
$b->estimated_wait_minutes = 20;
$b->estimated_start_at = now()->subMinutes(40)->toIso8601String();
$b->estimated_end_at = now()->subMinutes(10)->toIso8601String();
$b->save();

$fossilStart = (string) $b->estimated_start_at;
$aEndBefore = (string) $a->fresh()->estimated_end_at;

// ── the REAL pipeline: schedule:run dispatches → queue:work drains ──────
echo "schedule:run:\n";
passthru('/home/z/my-project/tools/php84 '.escapeshellarg(__DIR__.'/../artisan').' schedule:run 2>&1');
echo "queue:work:\n";
passthru('/home/z/my-project/tools/php84 '.escapeshellarg(__DIR__.'/../artisan').' queue:work --stop-when-empty --max-time=50 2>&1');

// ── assertions ───────────────────────────────────────────────────────────
$bFresh = $b->fresh();
$aFresh = $a->fresh();
$newStartTs = strtotime((string) $bFresh->estimated_start_at);
$fail = 0;

$check = function (string $name, bool $ok, string $detail = '') use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL')." — {$name}".($detail !== '' ? " ({$detail})" : '').PHP_EOL;
    if (! $ok) {
        $fail++;
    }
};

$check('B re-stamped away from the fossil', $newStartTs !== strtotime($fossilStart), "old={$fossilStart} new={$bFresh->estimated_start_at}");
$check('B start ≈ now (operator frees up now)', abs($newStartTs - now()->getTimestamp()) <= 65, 'start='.now()->format('Y-m-d H:i:s'));
$check('B wait = 0', (int) $bFresh->estimated_wait_minutes === 0);
$check('B end = now + 30min', abs(strtotime((string) $bFresh->estimated_end_at) - (now()->getTimestamp() + 1800)) <= 65);
$check('A own end estimate untouched', (string) $aFresh->estimated_end_at === $aEndBefore, "end={$aFresh->estimated_end_at}");

$failed = DB::table('failed_jobs')->count();
$check('failed_jobs = 0', $failed === 0, "count={$failed}");

echo $fail === 0 ? "\nE2E PIPELINE: ALL GREEN\n" : "\nE2E PIPELINE: {$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
