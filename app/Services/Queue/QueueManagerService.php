<?php

namespace App\Services\Queue;

use App\Contracts\QueueLockServiceInterface;
use App\Contracts\QueueManagerServiceInterface;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Concrete queue manager.
 *
 * All mutations run inside QueueLockService::withLock('queue:position', ...)
 * and a short DB::transaction. The lock serializes concurrent inserts;
 * the transaction keeps the order + queue_entry updates atomic.
 *
 * Positions are stored both on the order (queue_position) and in the
 * queue_entries row, so the public tracking endpoint can read straight
 * from orders without a join.
 */
class QueueManagerService implements QueueManagerServiceInterface
{
    public function __construct(
        private readonly QueueLockServiceInterface $locks,
    ) {}

    public function enqueue(Order $order): void
    {
        $this->locks->withLock('queue:position', function () use ($order) {
            DB::transaction(function () use ($order) {
                // Self-heal BEFORE reading max(position): orphaned entries
                // (order row gone) and position gaps must never feed the
                // next position, otherwise a fresh customer inherits phantom
                // «N نفرهای جلوی شما» from a deleted user's orders.
                $this->compactEntries();

                $next = (int) (DB::table('queue_entries')->max('position') ?? 0) + 1;
                $now = now()->toIso8601String();

                QueueEntry::create([
                    'order_id' => $order->id,
                    'position' => $next,
                    'entered_at' => $now,
                    'requeue_count' => 0,
                ]);

                $order->queue_position = $next;
                $order->updated_at = $now;
                $order->save();
            });
        }, ttlSeconds: 15);
    }

    public function dequeue(Order $order): void
    {
        $this->locks->withLock('queue:position', function () use ($order) {
            DB::transaction(function () use ($order) {
                $entry = QueueEntry::where('order_id', $order->id)->first();
                if (! $entry) {
                    return;
                }

                $removedPos = $entry->position;
                $entry->delete();

                // Repack positions > $removedPos downward by 1.
                DB::table('queue_entries')
                    ->where('position', '>', $removedPos)
                    ->decrement('position');

                // Sync queue_position on the still-waiting orders.
                $shifted = QueueEntry::where('position', '>=', $removedPos)->get();
                foreach ($shifted as $sh) {
                    DB::table('orders')->where('id', $sh->order_id)->update([
                        'queue_position' => $sh->position,
                        'updated_at' => now()->toIso8601String(),
                    ]);
                }

                $order->queue_position = null;
                $order->updated_at = now()->toIso8601String();
                $order->save();
            });
        }, ttlSeconds: 15);
    }

    public function startOrder(Order $order, ?string $operatorId = null): void
    {
        $this->locks->withLock("order:{$order->id}", function () use ($order, $operatorId) {
            DB::transaction(function () use ($order, $operatorId) {
                $now = now()->toIso8601String();
                $prevStatus = $order->status;

                $order->status = Order::STATUS_PROCESSING;
                $order->assigned_operator_id = $operatorId ?? $order->assigned_operator_id;
                $order->no_response_deadline = null;
                $order->updated_at = $now;
                $order->save();

                $entry = QueueEntry::where('order_id', $order->id)->first();
                if ($entry && ! $entry->started_at) {
                    $entry->started_at = $now;
                    // Starting IS the customer's "answered" signal — stop the
                    // no-response strike clock on the entry too (the sweep
                    // reads the entry, not the order).
                    $entry->no_response_deadline = null;
                    $entry->save();
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => $prevStatus,
                    'to_status' => Order::STATUS_PROCESSING,
                    'changed_by' => $operatorId ?? 'system',
                    'changed_at' => $now,
                ]);
            });
        }, ttlSeconds: 10);
    }

    public function finishOrder(Order $order): void
    {
        $this->locks->withLock("order:{$order->id}", function () use ($order) {
            DB::transaction(function () use ($order) {
                $now = now()->toIso8601String();
                $prevStatus = $order->status;

                $order->status = Order::STATUS_READY;
                $order->updated_at = $now;
                $order->save();

                $entry = QueueEntry::where('order_id', $order->id)->first();
                if ($entry) {
                    // Backfill started_at when the order was marked ready
                    // without ever being started (e.g. submitted → ready jump
                    // from the admin UI). Otherwise the entry keeps counting
                    // toward other customers' wait-time estimates forever.
                    if (! $entry->started_at) {
                        $entry->started_at = $now;
                    }
                    if (! $entry->finished_at) {
                        $entry->finished_at = $now;
                    }
                    $entry->save();
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => $prevStatus,
                    'to_status' => Order::STATUS_READY,
                    'changed_by' => 'system',
                    'changed_at' => $now,
                ]);
            });
        }, ttlSeconds: 10);
    }

    public function requeue(Order $order): void
    {
        $this->locks->withLock('queue:position', function () use ($order) {
            DB::transaction(function () use ($order) {
                $entry = QueueEntry::where('order_id', $order->id)->first();
                if (! $entry) {
                    return;
                }

                // Heal ghosts/gaps first so the count() below is the true
                // queue length and the tail slot is exact.
                $this->compactEntries();

                $now = now()->toIso8601String();
                $oldPos = (int) $entry->position;
                $entry->requeue_count = $entry->requeue_count + 1;
                $entry->no_response_deadline = null;
                $entry->save();

                // Everyone behind closes the vacated slot…
                DB::table('queue_entries')
                    ->where('position', '>', $oldPos)
                    ->decrement('position');

                // …and the order takes the now-vacated tail, so the queue
                // stays contiguous 1..N. The old max(position)+1 left a gap
                // at the tail and inflated the requeued order's own
                // «نفرهای جلوی شما = position - 1» by one phantom person.
                $next = (int) DB::table('queue_entries')->count();

                $order->queue_position = $next;
                $order->no_response_deadline = null;
                $order->updated_at = $now;
                $order->save();

                $entry->position = $next;
                $entry->save();

                // Sync every affected order's queue_position (the shifted ones
                // each moved up one, ours moved to the tail) so the tracker
                // reads consistent numbers.
                foreach (QueueEntry::all() as $e) {
                    DB::table('orders')->where('id', $e->order_id)->update([
                        'queue_position' => $e->position,
                        'updated_at' => $now,
                    ]);
                }

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'from_status' => $order->status,
                    'to_status' => $order->status,
                    'changed_by' => 'system',
                    'note' => "requeued to position {$next}",
                    'changed_at' => $now,
                ]);
            });
        }, ttlSeconds: 15);
    }

    public function snapshot(): array
    {
        return QueueEntry::with('order')
            ->orderBy('position')
            ->get()
            ->map(fn ($e) => [
                'position' => $e->position,
                'order_id' => $e->order_id,
                'order_number' => $e->order?->order_number,
                'customer_name' => $e->order?->customer_name,
                'queue_type' => $e->order?->queue_type,
                'status' => $e->order?->status,
                'entered_at' => $e->entered_at,
                'started_at' => $e->started_at,
                'estimated_duration_minutes' => $e->order?->estimated_duration_minutes,
                'no_response_deadline' => $e->no_response_deadline,
                'requeue_count' => $e->requeue_count,
            ])
            ->all();
    }

    public function waitingCount(): int
    {
        return QueueEntry::whereNull('started_at')->count();
    }

    /**
     * Standalone self-heal entry point: drops orphaned queue_entries and
     * repacks the survivors to contiguous positions. Used by the user purge
     * flow right after it deleted a (possibly queued) customer's orders, so
     * the remaining customers never keep phantom positions.
     *
     * MUST NOT be called while another transaction on the same connection is
     * still open — it takes the 'queue:position' lock itself.
     */
    public function compact(): void
    {
        $this->locks->withLock('queue:position', function () {
            DB::transaction(fn () => $this->compactEntries());
        }, ttlSeconds: 15);
    }

    /**
     * The actual healing, to be run while the 'queue:position' lock is held
     * and inside a short transaction (enqueue does both; compact() wraps it).
     *
     *  1. Delete entries whose order row no longer exists. queue_entries has
     *     no FK cascade, so any delete path that misses its cleanup leaves
     *     ghosts here — they inflate max(position) and the
     *     «ahead = position - 1» hero the tracker shows.
     *  2. Repack the surviving entries to contiguous 1..N preserving their
     *     relative order, syncing orders.queue_position so the live tracker
     *     (it re-reads QueueEntry) shows the healed number immediately.
     */
    private function compactEntries(): void
    {
        $orphanIds = QueueEntry::query()
            ->whereNotIn('order_id', Order::query()->select('id'))
            ->pluck('id')
            ->all();

        if (! empty($orphanIds)) {
            QueueEntry::whereIn('id', $orphanIds)->delete();
        }

        $pos = 0;
        foreach (QueueEntry::query()->orderBy('position')->orderBy('entered_at')->get() as $entry) {
            $pos++;
            if ((int) $entry->position === $pos) {
                continue;
            }
            $entry->position = $pos;
            $entry->save();

            DB::table('orders')->where('id', $entry->order_id)->update([
                'queue_position' => $pos,
                'updated_at' => now()->toIso8601String(),
            ]);
        }
    }
}
