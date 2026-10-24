<?php

/**
 * End-to-end pipeline check for the RefreshQueueEstimates GRACE WAVE:
 * real schedule:run dispatch → real queue:work → stamps actually moved.
 *
 * Scenario (owner spec): A is in progress and LONG over-running, B waits
 * behind it with enqueue-time fossil stamps.
 *
 *  Run 1 (the trigger): A's completion becomes NOW+20, B anchors to it
 *                       (start = now+20, wait = 20).
 *  Run 2 (same minute): the grace window cleared the condition — every
 *                       stamp must stay EXACTLY as run 1 wrote it
 *                       («جاب فقط یک‌بار بروز می‌کند»).
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

// A: started 60 min ago with a 20-min job → promised end = 40 min ago.
$a = makeOrder();
$a->estimated_duration_minutes = 20;
$a->save();
$queue->enqueue($a);
$queue->startOrder($a);
$aStartBefore = (string) $a->fresh()->estimated_start_at;
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
$bFossilStart = (string) $b->fresh()->estimated_start_at;

$artisan = '/home/z/my-project/tools/php84 '.escapeshellarg(__DIR__.'/../artisan');
$pipeline = function () use ($artisan): void {
    echo "schedule:run:\n";
    passthru($artisan.' schedule:run 2>&1');
    echo "queue:work:\n";
    passthru($artisan.' queue:work --stop-when-empty --max-time=50 2>&1');
};

$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL')." — {$name}".($detail !== '' ? " ({$detail})" : '').PHP_EOL;
    if (! $ok) {
        $fail++;
    }
};

// ── run 1: the grace wave ────────────────────────────────────────────────
$pipeline();

$fa = $a->fresh();
$fb = $b->fresh();
$aEnd1 = (string) $fa->estimated_end_at;
$snapshot = [
    'a_end' => $aEnd1,
    'a_start' => (string) $fa->estimated_start_at,
    'b_start' => (string) $fb->estimated_start_at,
    'b_end' => (string) $fb->estimated_end_at,
    'b_wait' => (int) $fb->estimated_wait_minutes,
    'b_dur' => (int) $fb->estimated_duration_minutes,
    'b_updated' => (string) $fb->updated_at,
];

$check('A completion re-stamped to NOW+20 (was 40 min ago)',
    abs(strtotime($snapshot['a_end']) - (now()->getTimestamp() + 1200)) <= 65,
    "end={$snapshot['a_end']}");
$check('A real start stamp untouched', $snapshot['a_start'] === $aStartBefore);
$check('B start anchored to A new end',
    abs(strtotime($snapshot['b_start']) - strtotime($snapshot['a_end'])) <= 5);
$check('B wait = 20 (the grace minutes)', $snapshot['b_wait'] === 20, "wait={$snapshot['b_wait']}");
$check('B duration kept (30)', $snapshot['b_dur'] === 30);
$check('B end = now + 50min',
    abs(strtotime($snapshot['b_end']) - (now()->getTimestamp() + 3000)) <= 65);
$check('B row actually rewritten by wave 1 (fossil gone)', $snapshot['b_start'] !== $bFossilStart,
    "fossil={$bFossilStart} new={$snapshot['b_start']}");

// ── run 2: inside the grace window — must be a complete no-op ───────────
$pipeline();

$fa2 = $a->fresh();
$fb2 = $b->fresh();
$check('run2: A end unchanged (no second push)', (string) $fa2->estimated_end_at === $snapshot['a_end']);
$check('run2: B start unchanged', (string) $fb2->estimated_start_at === $snapshot['b_start']);
$check('run2: B end unchanged', (string) $fb2->estimated_end_at === $snapshot['b_end']);
$check('run2: B wait unchanged', (int) $fb2->estimated_wait_minutes === $snapshot['b_wait']);
$check('run2: B row untouched (updated_at frozen)', (string) $fb2->updated_at === $snapshot['b_updated']);

$failed = DB::table('failed_jobs')->count();
$check('failed_jobs = 0', $failed === 0, "count={$failed}");

echo $fail === 0 ? "\nE2E PIPELINE: ALL GREEN\n" : "\nE2E PIPELINE: {$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
