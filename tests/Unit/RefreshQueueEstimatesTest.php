<?php

namespace Tests\Unit;

use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Jobs\RefreshQueueEstimates;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Services\Queue\QueueManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the RefreshQueueEstimates scheduled job — the one that keeps the
 * STORED ETAs of waiting orders honest while the order being worked on
 * runs long (or the queue drains early).
 *
 * Covered contracts:
 *  1. Over-running in-progress order → waiting orders behind it are
 *     re-stamped to "start now" (the operator frees up now).
 *  2. The in-progress order's OWN completion estimate is never touched —
 *     a broken promise stays visible instead of being fabricated anew.
 *  3. A still-true anchored schedule is left alone (no row churn).
 *  4. A stale FUTURE fossil (queue drained early / order ahead cancelled)
 *     is pulled back to reality.
 *  5. Empty queue / non-SUBMITTED rows are safely ignored.
 */
class RefreshQueueEstimatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOrderTypes();

        // One-operator shop (the parallelism the estimator feeds on).
        DB::table('operator_profiles')->insert([
            'id' => 'oprofile-rqe',
            'user_id' => 'usr-rqe-op',
            'display_name' => 'Operator',
            'capacity_per_hour' => 2,
            'is_active' => true,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function test_overrunning_in_progress_order_restamps_the_queue_behind_it(): void
    {
        $queue = app(QueueManagerService::class);

        // A: started 60 min ago, predicted to end 40 min ago — still running.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        // B: queued behind A with the ENQUEUE-TIME fossil — its stamps still
        // promise the schedule from before reality slipped.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 20;
        $b->estimated_start_at = now()->subMinutes(40)->toIso8601String();
        $b->estimated_end_at = now()->subMinutes(10)->toIso8601String();
        $b->save();

        $this->runJob();

        $fresh = $b->fresh();
        $startTs = strtotime((string) $fresh->estimated_start_at);

        // «شروع تقریبی» must be the CURRENT moment (operator frees up now),
        // not the 40-minute-old fossil.
        $this->assertGreaterThanOrEqual(now()->getTimestamp() - 5, $startTs);
        $this->assertLessThanOrEqual(now()->getTimestamp() + 5, $startTs);
        $this->assertSame(0, (int) $fresh->estimated_wait_minutes);
        $this->assertSame(30, (int) $fresh->estimated_duration_minutes);

        // ...and the completion estimate = now + its own duration.
        $this->assertSame(
            now()->addMinutes(30)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $fresh->estimated_end_at)->format('Y-m-d H:i')
        );
    }

    public function test_job_never_touches_the_in_progress_order_itself(): void
    {
        $queue = app(QueueManagerService::class);

        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);

        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $overrunEnd = now()->subMinutes(40)->toIso8601String();
        $a->estimated_end_at = $overrunEnd;
        $a->save();

        $this->runJob();

        // Nobody knows how much longer A takes — the job must NOT invent a
        // new completion time. The honest «دیر شده» stays visible.
        $this->assertSame($overrunEnd, (string) $a->fresh()->estimated_end_at);
    }

    public function test_still_true_anchored_schedule_is_left_untouched(): void
    {
        $queue = app(QueueManagerService::class);

        // A is in progress with a healthy 44-min future end.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 44;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        $a->refresh();

        // B stamped exactly on A's predicted end — the truthful anchor.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 44;
        $b->estimated_start_at = $a->fresh()->estimated_end_at;
        $b->estimated_end_at = \Illuminate\Support\Carbon::parse($a->fresh()->estimated_end_at)
            ->addMinutes(30)->toIso8601String();
        $b->save();
        $updatedAtBefore = $b->fresh()->updated_at;

        $this->runJob();

        $fresh = $b->fresh();
        $this->assertSame((string) $updatedAtBefore, (string) $fresh->updated_at, 'job must not rewrite a still-true schedule');
        $this->assertSame(44, (int) $fresh->estimated_wait_minutes);
        $this->assertSame(
            strtotime((string) $a->fresh()->estimated_end_at),
            strtotime((string) $fresh->estimated_start_at)
        );
    }

    public function test_stale_future_fossil_is_pulled_back_when_queue_drained_early(): void
    {
        $queue = app(QueueManagerService::class);

        // B still carries a future start promised by an order ahead that
        // has since been cancelled — the queue is actually free NOW.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 15;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 40;
        $b->estimated_start_at = now()->addMinutes(40)->toIso8601String();
        $b->estimated_end_at = now()->addMinutes(55)->toIso8601String();
        $b->save();

        $this->runJob();

        $fresh = $b->fresh();
        $startTs = strtotime((string) $fresh->estimated_start_at);
        $this->assertLessThanOrEqual(now()->getTimestamp() + 5, $startTs, 'free queue must not keep promising a 40-min wait');
        $this->assertSame(0, (int) $fresh->estimated_wait_minutes);
    }

    public function test_job_is_a_safe_noop_without_waiting_orders(): void
    {
        // No operators, no entries — must not throw and must not write.
        DB::table('operator_profiles')->where('id', 'oprofile-rqe')->delete();

        $this->runJob();
        $this->assertSame(0, QueueEntry::count());
    }

    public function test_job_skips_rows_that_are_not_genuinely_queued(): void
    {
        $queue = app(QueueManagerService::class);

        // A pending-payment order (not SUBMITTED) that somehow has stamps —
        // the job must leave it for the payment flow to stamp.
        $pending = $this->makeOrder(['status' => Order::STATUS_PENDING_PAYMENT]);
        $pending->estimated_start_at = now()->subMinutes(90)->toIso8601String();
        $pending->save();
        $queue->enqueue($pending);

        $this->runJob();

        $this->assertSame(
            strtotime(now()->subMinutes(90)->toIso8601String()),
            strtotime((string) $pending->fresh()->estimated_start_at)
        );
    }

    /* ---------- helpers ---------- */

    private function runJob(): void
    {
        app(RefreshQueueEstimates::class)
            ->handle(app(WaitTimeEstimatorServiceInterface::class));
    }

    private function seedOrderTypes(): void
    {
        foreach ([
            ['key' => 'quick', 'title' => 'فوری', 'base_duration_minutes' => 5, 'priority_level' => 9, 'allow_inline' => true],
            ['key' => 'normal', 'title' => 'عادی', 'base_duration_minutes' => 20, 'priority_level' => 5, 'allow_inline' => true],
            ['key' => 'long', 'title' => 'زمان‌بر', 'base_duration_minutes' => 60, 'priority_level' => 3, 'allow_inline' => false],
        ] as $row) {
            DB::table('order_types')->insert(array_merge($row, ['id' => 'otype-'.\Illuminate\Support\Str::uuid()]));
        }
    }

    private function makeOrder(array $overrides = []): Order
    {
        $order = new Order();
        $order->id = 'ord-'.\Illuminate\Support\Str::uuid();
        $order->order_number = 'CN-'.random_int(1000, 9999);
        $order->service_id = 'svc-test';
        $order->service_title = 'Test Service';
        $order->customer_id = 'usr-test';
        $order->customer_phone = '09120000000';
        $order->customer_name = 'Tester';
        $order->status = $overrides['status'] ?? Order::STATUS_SUBMITTED;
        $order->urgency = 'normal';
        $order->queue_type = 'normal';
        $order->priority_level = 5;
        $order->form_data_json = '[]';
        $order->customer_files_json = '[]';
        $order->result_files_json = '[]';
        $order->total_price = 1000;
        $order->paid_amount = 1000;
        $order->payment_method = 'wallet';
        $order->payment_tracking_code = '';
        $order->created_at = now()->toIso8601String();
        $order->updated_at = now()->toIso8601String();
        $order->save();

        return $order;
    }
}
