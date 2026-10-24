<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderType;
use App\Models\QueueEntry;
use App\Services\Queue\OrderTypeClassifierService;
use App\Services\Queue\QueueLockService;
use App\Services\Queue\QueueManagerService;
use App\Services\Queue\WaitTimeEstimatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for the smart queue core services.
 *
 * Covers:
 *  - QueueLockService: acquire / release / TTL expiry
 *  - QueueManagerService: enqueue / dequeue / position repack / requeue
 *  - WaitTimeEstimatorService: empty queue, single order, parallelism
 *  - OrderTypeClassifierService: text → quick/normal/long mapping
 */
class QueueServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOrderTypes();
    }

    public function test_lock_acquire_and_release(): void
    {
        $locks = app(QueueLockService::class);

        $this->assertTrue($locks->acquire('test:resource', ttlSeconds: 5));
        // Re-acquiring the same key without release should fail within retries.
        $this->assertFalse($locks->acquire('test:resource', ttlSeconds: 5, maxRetries: 2, sleepMs: 10));
    }

    public function test_lock_with_lock_releases_on_success(): void
    {
        $locks = app(QueueLockService::class);

        $result = $locks->withLock('test:callback', fn () => 42, ttlSeconds: 5);
        $this->assertSame(42, $result);

        // Lock should be released; a new acquire should succeed.
        $this->assertTrue($locks->acquire('test:callback', ttlSeconds: 5));
    }

    public function test_enqueue_assigns_sequential_positions(): void
    {
        $queue = app(QueueManagerService::class);

        $o1 = $this->makeOrder();
        $o2 = $this->makeOrder();
        $o3 = $this->makeOrder();

        $queue->enqueue($o1);
        $queue->enqueue($o2);
        $queue->enqueue($o3);

        $this->assertSame(1, $o1->fresh()->queue_position);
        $this->assertSame(2, $o2->fresh()->queue_position);
        $this->assertSame(3, $o3->fresh()->queue_position);
    }

    public function test_dequeue_repacks_positions(): void
    {
        $queue = app(QueueManagerService::class);

        $o1 = $this->makeOrder();
        $o2 = $this->makeOrder();
        $o3 = $this->makeOrder();

        $queue->enqueue($o1);
        $queue->enqueue($o2);
        $queue->enqueue($o3);

        $queue->dequeue($o2);

        $this->assertNull($o2->fresh()->queue_position);
        $this->assertSame(1, $o1->fresh()->queue_position);
        $this->assertSame(2, $o3->fresh()->queue_position);
    }

    public function test_requeue_moves_to_back(): void
    {
        $queue = app(QueueManagerService::class);

        $o1 = $this->makeOrder();
        $o2 = $this->makeOrder();
        $o3 = $this->makeOrder();

        $queue->enqueue($o1);
        $queue->enqueue($o2);
        $queue->enqueue($o3);

        $queue->requeue($o1);

        $this->assertSame(3, $o1->fresh()->queue_position);
        $this->assertSame(1, $o2->fresh()->queue_position);
        $this->assertSame(2, $o3->fresh()->queue_position);

        $entry = QueueEntry::where('order_id', $o1->id)->first();
        $this->assertSame(1, $entry->requeue_count);
    }

    public function test_wait_time_estimator_empty_queue(): void
    {
        $est = app(WaitTimeEstimatorService::class);
        $this->assertSame(0, $est->estimateWaitMinutes());
    }

    public function test_wait_time_estimator_with_orders(): void
    {
        $queue = app(QueueManagerService::class);
        $est = app(WaitTimeEstimatorService::class);

        // Add an active operator so parallelism is 1 (not 0).
        DB::table('operator_profiles')->insert([
            'id' => 'oprofile-test',
            'user_id' => 'usr-test-op',
            'display_name' => 'Operator',
            'capacity_per_hour' => 2,
            'is_active' => true,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $o1 = $this->makeOrder(['queue_type' => 'normal']); // 20 min
        $o2 = $this->makeOrder(['queue_type' => 'normal']); // 20 min

        $queue->enqueue($o1);
        $queue->enqueue($o2);

        // Two normal orders ahead → 40 min wait / 1 operator = 40 min.
        $this->assertSame(40, $est->estimateWaitMinutes());
    }

    public function test_wait_accounts_for_the_order_being_worked_on_right_now(): void
    {
        $queue = app(QueueManagerService::class);
        $est = app(WaitTimeEstimatorService::class);

        // One operator shop: A is IN PROGRESS (started 44-min job) when B
        // joins the queue. B must wait for A's remaining work — the old
        // sum-of-waiting-durations model said «اکنون» because started
        // orders were excluded entirely.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 44;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);

        $b = $this->makeOrder();
        $b->estimated_duration_minutes = 30;
        $b->save();
        $queue->enqueue($b);

        // B waits ≈ A's full remaining duration (44 min), not zero.
        $estB = $est->estimateForOrder($b);
        $this->assertSame(44, $estB['wait_minutes']);
        $this->assertSame(30, $estB['duration_minutes']);

        // A hypothetical NEW order C would queue behind B: 44 + 30.
        $this->assertSame(74, $est->estimateWaitMinutes());
    }

    public function test_overrunning_in_progress_order_frees_the_operator_now(): void
    {
        $queue = app(QueueManagerService::class);
        $est = app(WaitTimeEstimatorService::class);

        // A over-ran its estimate long ago (end est. 40 min in the past).
        // Nobody can know how much longer it takes — the only honest
        // promise is "the operator frees up now" (wait 0, never negative).
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);

        QueueEntry::where('order_id', $a->id)->update(['started_at' => now()->subMinutes(60)->toIso8601String()]);
        $a->estimated_end_at = now()->subMinutes(40)->toIso8601String();
        $a->save();

        $b = $this->makeOrder();
        $queue->enqueue($b);

        $this->assertSame(0, $est->estimateForOrder($b)['wait_minutes']);
    }

    public function test_second_operator_serves_the_next_order_in_parallel(): void
    {
        $queue = app(QueueManagerService::class);
        $est = app(WaitTimeEstimatorService::class);

        DB::table('operator_profiles')->insert([
            ['id' => 'oprofile-p1', 'user_id' => 'usr-op-1', 'display_name' => 'Op1',
                'capacity_per_hour' => 2, 'is_active' => true,
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
            ['id' => 'oprofile-p2', 'user_id' => 'usr-op-2', 'display_name' => 'Op2',
                'capacity_per_hour' => 2, 'is_active' => true,
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()],
        ]);

        // Two operators; only one is busy with a future-end job → the new
        // order takes the FREE slot immediately.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 40;
        $a->save();
        $queue->enqueue($a);
        $queue->startOrder($a);

        $b = $this->makeOrder();
        $queue->enqueue($b);

        $this->assertSame(0, $est->estimateForOrder($b)['wait_minutes']);
    }

    public function test_start_restamps_the_completion_estimate_around_the_real_start(): void
    {
        $queue = app(QueueManagerService::class);

        // The ETA was frozen at enqueue time; make the fossil visible.
        $a = $this->makeOrder();
        $a->estimated_duration_minutes = 20;
        $a->estimated_end_at = now()->subMinutes(15)->toIso8601String(); // past fossil
        $a->save();
        $queue->enqueue($a);

        $queue->startOrder($a);
        $fresh = $a->fresh();

        // «تکمیل تقریبی» must now be actual start + duration — a future
        // timestamp again, not the creation-time fossil.
        $this->assertGreaterThan(now(), new \DateTimeImmutable((string) $fresh->estimated_end_at));
        $this->assertSame(
            now()->addMinutes(20)->format('Y-m-d H:i'),
            \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) $fresh->estimated_end_at)->format('Y-m-d H:i')
        );
    }

    public function test_classifier_quick(): void
    {
        $c = app(OrderTypeClassifierService::class);
        $this->assertSame(OrderType::KEY_QUICK, $c->classify('۵ دقیقه'));
        $this->assertSame(OrderType::KEY_QUICK, $c->classify('5 min'));
        $this->assertSame(OrderType::KEY_QUICK, $c->classify('فوری'));
    }

    public function test_classifier_long(): void
    {
        $c = app(OrderTypeClassifierService::class);
        $this->assertSame(OrderType::KEY_LONG, $c->classify('۱ ساعت'));
        $this->assertSame(OrderType::KEY_LONG, $c->classify('90 دقیقه'));
        $this->assertSame(OrderType::KEY_LONG, $c->classify('زمان‌بر'));
    }

    public function test_classifier_normal_default(): void
    {
        $c = app(OrderTypeClassifierService::class);
        $this->assertSame(OrderType::KEY_NORMAL, $c->classify(''));
        $this->assertSame(OrderType::KEY_NORMAL, $c->classify(null));
        $this->assertSame(OrderType::KEY_NORMAL, $c->classify('20 دقیقه'));
    }

    // ── Helpers ────────────────────────────────────────────────────────

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
        $order->status = Order::STATUS_SUBMITTED;
        $order->urgency = 'normal';
        $order->queue_type = $overrides['queue_type'] ?? 'normal';
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
