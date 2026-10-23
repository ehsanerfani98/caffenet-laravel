<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Queue\QueueManagerService;
use App\Services\UserPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: purging a user deleted their orders but NOT their queue_entries
 * rows (no FK cascade on the table). The orphaned ghosts kept feeding
 * enqueue's max(position)+1 and the tracker's «ahead = position - 1» hero, so
 * a brand-new customer's very first order could land at «نفر چهارم در صف /
 * ۳ نفر جلوی شما» on an otherwise empty queue (production report).
 *
 * Guards the three healing layers:
 *  - UserPurgeService removes the entries of purged orders + compacts;
 *  - QueueManagerService::enqueue() self-heals (orphans + gaps) before
 *    reading max(position) — covers pre-fix data and any future delete path
 *    that misses its cleanup;
 *  - positions stay contiguous 1..N with orders.queue_position in sync.
 */
class QueueOrphanSelfHealTest extends TestCase
{
    use RefreshDatabase;

    public function test_purging_user_deletes_their_queue_entries_and_heals_numbering(): void
    {
        $victim = $this->makeUser('usr-victim', '09130000001');
        $victimOrder = $this->makeOrder(['customer_id' => $victim->id]);
        $keeper = $this->makeOrder();

        $queue = app(QueueManagerService::class);
        $queue->enqueue($victimOrder); // position 1
        $queue->enqueue($keeper);      // position 2

        UserPurgeService::purge($victim);

        // The purged order's queue entry is gone — no ghost left behind.
        $this->assertNull(QueueEntry::where('order_id', $victimOrder->id)->first());
        $this->assertSame(1, (int) QueueEntry::count());

        // The survivor was repacked: no phantom position-2 display.
        $this->assertSame(1, $keeper->fresh()->queue_position);
        $this->assertSame(1, (int) QueueEntry::where('order_id', $keeper->id)->value('position'));

        // The next customer continues from a healthy numbering.
        $fresh = $this->makeOrder();
        $queue->enqueue($fresh);
        $this->assertSame(2, $fresh->fresh()->queue_position);
    }

    public function test_enqueue_self_heals_orphaned_ghost_entries(): void
    {
        $queue = app(QueueManagerService::class);

        // Simulate the pre-fix production state: entries whose order rows are
        // already gone (a purge that skipped queue_entries cleanup). Before
        // the fix the first real order below inherited position 4 from the
        // ghosts and the tracker showed «۳ نفر جلوی شما».
        foreach (['ord-ghost-1', 'ord-ghost-2', 'ord-ghost-3'] as $i => $ghostId) {
            QueueEntry::create([
                'order_id' => $ghostId,
                'position' => $i + 1,
                'entered_at' => now()->toIso8601String(),
                'requeue_count' => 0,
            ]);
        }

        // enqueue() compacts BEFORE reading max(position): the ghosts are
        // purged and the very first real order takes position 1 — never a
        // phantom position derived from deleted orders.
        $stuck = $this->makeOrder();
        $queue->enqueue($stuck);
        $this->assertSame(1, $stuck->fresh()->queue_position);

        $this->assertSame(0, (int) QueueEntry::whereNotIn('order_id', Order::query()->select('id'))->count());

        // Numbering continues healthily from the compacted queue.
        $fresh = $this->makeOrder();
        $queue->enqueue($fresh);
        $this->assertSame([1, 2], QueueEntry::orderBy('position')->pluck('position')->all());
        $this->assertSame(2, $fresh->fresh()->queue_position);
    }

    public function test_enqueue_heals_position_gaps_and_syncs_order_positions(): void
    {
        $queue = app(QueueManagerService::class);

        $o1 = $this->makeOrder();
        $o2 = $this->makeOrder();
        $o3 = $this->makeOrder();
        $queue->enqueue($o1);
        $queue->enqueue($o2);
        $queue->enqueue($o3);

        // A cleanup that removed the ENTRY row but skipped the repack leaves
        // a gap: positions 1, 3 with nothing at 2.
        QueueEntry::where('order_id', $o2->id)->delete();

        $o4 = $this->makeOrder();
        $queue->enqueue($o4);

        // Without healing, o4 would land at max(3)+1 = 4 and the tracker
        // would claim «۳ نفر جلوی شما» while only two orders exist.
        $this->assertSame([1, 2, 3], QueueEntry::orderBy('position')->pluck('position')->all());
        $this->assertSame(1, $o1->fresh()->queue_position);
        $this->assertSame(2, $o3->fresh()->queue_position);
        $this->assertSame(3, $o4->fresh()->queue_position);
        $this->assertSame(2, (int) QueueEntry::where('order_id', $o3->id)->value('position'));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function makeUser(string $id, string $phone): User
    {
        return User::create([
            'id' => $id,
            'phone' => $phone,
            'name' => 'کاربر آزمایشی',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
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
        $order->created_at = now()->toIso8601String();
        $order->updated_at = now()->toIso8601String();
        $order->save();

        return $order;
    }
}
