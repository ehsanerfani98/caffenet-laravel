<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\QueueEntry;
use App\Services\Queue\QueueManagerService;
use App\Services\Queue\WaitTimeEstimatorService;
use App\Services\UserPurgeService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 37 queue-behaviour guards:
 *  - requeue lands on the CONTIGUOUS tail (1..N) — the old max(position)+1
 *    left a gap at the tail and inflated the requeued order's own
 *    «نفرهای جلوی شما = position - 1»;
 *  - three no-response cycles push the order back, the fourth expired
 *    deadline CANCELS it entirely (auto-cancel mirrors the manual path);
 *  - started orders are never swept, even with a stale deadline;
 *  - delivering/cancelling an order advances the queue for everyone behind
 *    (positions repack + live wait recompute shrinks);
 *  - request-mode per-order durations drive the wait estimate.
 */
class QueueNoResponseStrikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_requeue_lands_on_contiguous_tail_without_gap(): void
    {
        $queue = app(QueueManagerService::class);

        $o1 = $this->makeOrder();
        $o2 = $this->makeOrder();
        $o3 = $this->makeOrder();
        $queue->enqueue($o1);
        $queue->enqueue($o2);
        $queue->enqueue($o3);

        $queue->requeue($o1);

        // 1..N contiguous — no gap at the tail.
        $this->assertSame([1, 2, 3], QueueEntry::orderBy('position')->pluck('position')->all());
        $this->assertSame(3, $o1->fresh()->queue_position);
        $this->assertSame(1, $o2->fresh()->queue_position);
        $this->assertSame(2, $o3->fresh()->queue_position);
        $this->assertSame(1, (int) QueueEntry::where('order_id', $o1->id)->value('requeue_count'));
    }

    public function test_three_no_response_cycles_then_auto_cancel(): void
    {
        $auto = app(\App\Contracts\AutoRequeueServiceInterface::class);
        $queue = app(QueueManagerService::class);

        $victim = $this->makeOrder();
        $bystander = $this->makeOrder();
        $queue->enqueue($victim);    // position 1
        $queue->enqueue($bystander); // position 2

        // Cycle 1..3: each expired deadline pushes the order to the back.
        for ($i = 1; $i <= 3; $i++) {
            $this->armExpiredDeadline($victim);
            $this->assertSame('requeued', $auto->requeueExpired($victim));
            $this->assertSame($i, (int) QueueEntry::where('order_id', $victim->id)->value('requeue_count'));
            $this->assertSame(Order::STATUS_SUBMITTED, $victim->fresh()->status);
        }

        // The 4th unanswered deadline (after 3 pushes to the back) cancels it.
        $this->armExpiredDeadline($victim);
        $this->assertSame('cancelled', $auto->requeueExpired($victim));

        $this->assertSame(Order::STATUS_CANCELLED, $victim->fresh()->status);
        $this->assertNull(QueueEntry::where('order_id', $victim->id)->first());

        // The bystander advanced to the head — the queue moved forward.
        $this->assertSame(1, $bystander->fresh()->queue_position);
        $this->assertSame([1], QueueEntry::orderBy('position')->pluck('position')->all());
    }

    public function test_started_order_is_never_swept(): void
    {
        $auto = app(\App\Contracts\AutoRequeueServiceInterface::class);
        $queue = app(QueueManagerService::class);

        $o = $this->makeOrder();
        $queue->enqueue($o);
        $queue->startOrder($o);

        // Stale deadline survives on the entry, but started_at is set.
        QueueEntry::where('order_id', $o->id)->update([
            'no_response_deadline' => now()->subMinutes(30)->toIso8601String(),
        ]);

        $this->assertSame([], $auto->findExpired());
        $this->assertSame('skipped', $auto->requeueExpired($o)); // started orders are never struck
        $this->assertSame(Order::STATUS_PROCESSING, $o->fresh()->status);
        $this->assertSame(0, (int) QueueEntry::where('order_id', $o->id)->value('requeue_count'));
    }

    public function test_delivered_order_advances_queue_and_shrinks_wait(): void
    {
        $queue = app(QueueManagerService::class);
        $est = app(WaitTimeEstimatorService::class);
        $lifecycle = app(\App\Services\Queue\OrderLifecycleService::class);

        // Two waiting orders; the first carries a 45-min per-order duration
        // (the request-mode style draw).
        $a = $this->makeOrder(['duration' => 45]);
        $b = $this->makeOrder(['duration' => 30]);
        $lifecycle->onSubmitted($a);
        $lifecycle->onSubmitted($b);

        $this->assertSame(1, $a->fresh()->queue_position);
        $this->assertSame(2, $b->fresh()->queue_position);
        // B waits for A's full 45 minutes (1 operator).
        $this->assertSame(45, $est->estimateForOrder($b)['wait_minutes']);

        // Delivery advances the queue: B becomes head, wait drops to 0.
        $lifecycle->onDelivered($a);

        $this->assertNull(QueueEntry::where('order_id', $a->id)->first());
        $this->assertSame(1, $b->fresh()->queue_position);
        $live = $est->estimateForOrder($b);
        $this->assertSame(0, $live['wait_minutes']);
        $this->assertSame(30, $live['duration_minutes']);
    }

    public function test_purge_of_user_with_queued_order_keeps_queue_healthy(): void
    {
        $queue = app(QueueManagerService::class);

        $user = User::create([
            'id' => 'usr-purge-q', 'phone' => '09140000001', 'name' => 'کاربر صف',
            'role' => 'customer', 'wallet_balance' => 0, 'created_at' => now()->toIso8601String(),
        ]);
        $a = $this->makeOrder(['customer_id' => $user->id, 'duration' => 40]);
        $b = $this->makeOrder(['duration' => 30]);
        $queue->enqueue($a);
        $queue->enqueue($b);

        UserPurgeService::purge($user);

        // Purge removed the entry AND compacted: survivor at 1, no ghosts.
        $this->assertSame(1, (int) QueueEntry::count());
        $this->assertSame(1, $b->fresh()->queue_position);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** Arm an already-expired no-response deadline on entry + order. */
    private function armExpiredDeadline(Order $order): void
    {
        $past = now()->subMinutes(20)->toIso8601String();
        QueueEntry::where('order_id', $order->id)->update(['no_response_deadline' => $past]);
        DB::table('orders')->where('id', $order->id)->update(['no_response_deadline' => $past]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $order = new Order();
        $order->id = 'ord-'.\Illuminate\Support\Str::uuid();
        $order->order_number = 'CN-'.random_int(1000, 9999);
        $order->service_id = 'svc-test';
        $order->service_title = 'Test Service';
        $order->customer_id = $overrides['customer_id'] ?? 'usr-test';
        $order->customer_phone = '09120000000';
        $order->customer_name = 'Tester';
        $order->status = Order::STATUS_SUBMITTED;
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
        if (! empty($overrides['duration'])) {
            $order->estimated_duration_minutes = $overrides['duration'];
        }
        $order->created_at = now()->toIso8601String();
        $order->updated_at = now()->toIso8601String();
        $order->save();

        return $order;
    }
}
