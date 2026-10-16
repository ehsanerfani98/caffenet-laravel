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

                $oldPos = $entry->position;
                $next = (int) (DB::table('queue_entries')->max('position') ?? 0) + 1;
                $now = now()->toIso8601String();

                $entry->position = $next;
                $entry->requeue_count = $entry->requeue_count + 1;
                $entry->no_response_deadline = null;
                $entry->save();

                // Shift everyone between $oldPos+1 and $next-1 up by 1 to fill the gap.
                DB::table('queue_entries')
                    ->where('position', '>', $oldPos)
                    ->where('position', '<', $next)
                    ->decrement('position');

                $order->queue_position = $next;
                $order->no_response_deadline = null;
                $order->updated_at = $now;
                $order->save();

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
}
