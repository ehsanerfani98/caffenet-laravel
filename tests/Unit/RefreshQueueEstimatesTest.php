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
 * Pins the grace-wave contract of RefreshQueueEstimates:
 *
 *  - TRIGGER: now has passed the in-progress order's estimated_end_at.
 *  - ACTION (once per occurrence): that order's completion estimate
 *    becomes NOW + 20 min and every BLOCKED waiting order slides to the
 *    re-anchored chain (~+20 min later).
 *  - WAIT: nothing is rewritten again until the new end estimate passes.
 *  - Structural drift (queue sped up / stale fossil) still re-anchors,
 *    but never while a wave is running, and a fresh "startable now"
 *    stamp stays frozen.
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

    public function test_overrun_fires_one_grace_wave(): void
    {
        $queue = app(QueueManagerService::class);

        // A: started 60 min ago with a 20-min job → promised end = 40 min ago.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        $aStartStamp = (string) $a->fresh()->estimated_start_at;
        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        // B: waiting behind A with the enqueue-time fossil.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 20;
        $b->estimated_start_at = now()->subMinutes(40)->toIso8601String();
        $b->estimated_end_at = now()->subMinutes(10)->toIso8601String();
        $b->save();

        $this->runJob();

        $fa = $a->fresh();
        $fb = $b->fresh();

        // The over-running order's own completion = NOW + 20 min…
        $this->assertSame(
            now()->addMinutes(20)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $fa->estimated_end_at)->format('Y-m-d H:i')
        );
        // …its REAL start stamp is never rewritten.
        $this->assertSame($aStartStamp, (string) $fa->estimated_start_at);

        // …and B slides to the re-anchored chain: start = A's new end.
        $this->assertSame(
            strtotime((string) $fa->estimated_end_at),
            strtotime((string) $fb->estimated_start_at),
            'waiting order must anchor to the grace-extended end'
        );
        $this->assertSame(20, (int) $fb->estimated_wait_minutes);
        $this->assertSame(30, (int) $fb->estimated_duration_minutes);
        $this->assertSame(
            now()->addMinutes(50)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $fb->estimated_end_at)->format('Y-m-d H:i')
        );
    }

    public function test_grace_window_prevents_a_second_push(): void
    {
        $queue = app(QueueManagerService::class);

        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);

        $this->runJob(); // wave 1

        $snapshot = [
            'a_end' => (string) $a->fresh()->estimated_end_at,
            'b_start' => (string) $b->fresh()->estimated_start_at,
            'b_end' => (string) $b->fresh()->estimated_end_at,
            'b_wait' => (int) $b->fresh()->estimated_wait_minutes,
            'b_updated' => (string) $b->fresh()->updated_at,
        ];

        // Same clock: the extension cleared the condition — the job must
        // be a complete no-op («جاب فقط یک‌بار بروز کند»).
        $this->runJob();

        $fb = $b->fresh();
        $this->assertSame($snapshot['a_end'], (string) $a->fresh()->estimated_end_at);
        $this->assertSame($snapshot['b_start'], (string) $fb->estimated_start_at);
        $this->assertSame($snapshot['b_end'], (string) $fb->estimated_end_at);
        $this->assertSame($snapshot['b_wait'], (int) $fb->estimated_wait_minutes);
        $this->assertSame($snapshot['b_updated'], (string) $fb->updated_at, 'no row may be rewritten inside the grace window');
    }

    public function test_wave_repeats_only_after_the_grace_window_passes(): void
    {
        $queue = app(QueueManagerService::class);

        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);

        $this->runJob(); // wave 1: B.start = now+20
        $firstStart = strtotime((string) $b->fresh()->estimated_start_at);

        // 21 minutes later the extended promise has passed again → the
        // condition recurs → exactly one more +20 wave.
        $this->travel(21)->minutes();
        $this->runJob();

        $fa = $a->fresh();
        $fb = $b->fresh();
        $this->assertSame(
            now()->addMinutes(20)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $fa->estimated_end_at)->format('Y-m-d H:i')
        );
        $this->assertSame(
            strtotime((string) $fa->estimated_end_at),
            strtotime((string) $fb->estimated_start_at)
        );
        // B anchored to the NEW «now + 20» — it slid forward by the elapsed
        // 21 minutes (any real re-fire), not by a stale repeat of wave 1.
        $this->assertGreaterThan($firstStart + 20 * 60, strtotime((string) $fb->estimated_start_at));
    }

    public function test_healthy_in_progress_order_never_triggers(): void
    {
        $queue = app(QueueManagerService::class);

        // A is in progress with a healthy 44-min future end.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 44;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        $a->refresh();

        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 44;
        $b->estimated_start_at = $a->fresh()->estimated_end_at;
        $b->estimated_end_at = \Illuminate\Support\Carbon::parse($a->fresh()->estimated_end_at)
            ->addMinutes(30)->toIso8601String();
        $b->save();
        $bUpdatedBefore = (string) $b->fresh()->updated_at;

        $this->runJob();

        // Condition (now > est_end) is false → zero writes anywhere.
        $this->assertSame(
            strtotime((string) $a->fresh()->estimated_end_at),
            strtotime((string) $b->fresh()->estimated_start_at)
        );
        $this->assertSame($bUpdatedBefore, (string) $b->fresh()->updated_at, 'no row may be rewritten while the schedule holds');
        $this->assertSame(44, (int) $b->fresh()->estimated_wait_minutes);
    }

    public function test_a_free_second_operator_shields_the_next_order(): void
    {
        // Two operators: the over-running A holds op-1, but op-2 is free —
        // B must NOT be pushed +20; it anchors to the free slot (now).
        DB::table('operator_profiles')->insert([
            'id' => 'oprofile-rqe-2',
            'user_id' => 'usr-rqe-op-2',
            'display_name' => 'Operator 2',
            'capacity_per_hour' => 2,
            'is_active' => true,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $queue = app(QueueManagerService::class);

        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);
        QueueEntry::where('order_id', $a->id)
            ->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);

        $this->runJob();

        // A still got its grace extension…
        $this->assertSame(
            now()->addMinutes(20)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $a->fresh()->estimated_end_at)->format('Y-m-d H:i')
        );
        // …but B is served by the free operator now, not pushed +20.
        $this->assertSame(0, (int) $b->fresh()->estimated_wait_minutes);
        $this->assertLessThanOrEqual(now()->getTimestamp() + 5, strtotime((string) $b->fresh()->estimated_start_at));
    }

    public function test_stale_future_fossil_is_pulled_back_without_a_wave(): void
    {
        $queue = app(QueueManagerService::class);

        // No in-progress order at all: B still promises a start 40 min
        // from now, promised by an order ahead that has since been
        // cancelled — the structural pass re-anchors it.
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
        $this->assertLessThanOrEqual(now()->getTimestamp() + 5, strtotime((string) $fresh->estimated_start_at));
        $this->assertSame(0, (int) $fresh->estimated_wait_minutes);
    }

    public function test_fresh_due_now_claim_stays_frozen(): void
    {
        $queue = app(QueueManagerService::class);

        // B was already stamped "startable now" two minutes ago and the
        // queue is still free — the freeze window must keep the job from
        // rewriting it every run.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 15;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 0;
        $b->estimated_start_at = now()->subMinutes(2)->toIso8601String();
        $b->estimated_end_at = now()->addMinutes(13)->toIso8601String();
        $b->save();
        $updatedBefore = (string) $b->fresh()->updated_at;

        $this->runJob();

        $fresh = $b->fresh();
        $this->assertSame($updatedBefore, (string) $fresh->updated_at, 'a fresh startable-now claim must not be rewritten');
        $this->assertSame(
            strtotime(now()->subMinutes(2)->toIso8601String()),
            strtotime((string) $fresh->estimated_start_at)
        );
    }

    public function test_ancient_due_now_fossil_gets_re_anchored(): void
    {
        $queue = app(QueueManagerService::class);

        // Same shape, but the "startable now" stamp is 15 minutes old —
        // past the freeze window → one re-anchor write.
        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 15;
        $b->save();
        $queue->enqueue($b);
        $b->estimated_wait_minutes = 0;
        $b->estimated_start_at = now()->subMinutes(15)->toIso8601String();
        $b->estimated_end_at = now()->toIso8601String();
        $b->save();

        $this->runJob();

        $fresh = $b->fresh();
        $this->assertGreaterThanOrEqual(now()->getTimestamp() - 5, strtotime((string) $fresh->estimated_start_at));
        $this->assertLessThanOrEqual(now()->getTimestamp() + 5, strtotime((string) $fresh->estimated_start_at));
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
