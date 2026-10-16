<?php

namespace App\Services\Queue;

use App\Contracts\QueueManagerServiceInterface;
use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OrderLifecycleService — orchestrates the queue-related status transitions
 * on an Order, recording history + invoking the queue manager.
 *
 * This is the single entry point that controllers call when they need to
 * move an order between queue-relevant states. It guarantees:
 *  - Status history is always written.
 *  - Queue entry is created/deleted in lockstep.
 *  - Estimated times are recomputed on enqueue.
 *  - track_token is generated on first enqueue (for public tracking).
 */
class OrderLifecycleService implements \App\Contracts\OrderLifecycleService
{
    public function __construct(
        private readonly QueueManagerServiceInterface $queue,
        private readonly WaitTimeEstimatorServiceInterface $estimator,
    ) {}

    /**
     * Called when an order becomes `submitted` (post-payment verified).
     * Enqueues it and stamps the ETA + track token.
     */
    public function onSubmitted(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $queueType = $order->queue_type ?: 'normal';
            $wait = $this->estimator->estimateWaitMinutes($queueType);
            $duration = $this->estimator->estimateDurationMinutes($queueType);

            $order->estimated_wait_minutes = $wait;
            $order->estimated_duration_minutes = $duration;
            $order->estimated_start_at = now()->addMinutes($wait)->toIso8601String();
            $order->estimated_end_at = now()->addMinutes($wait + $duration)->toIso8601String();
            if (! $order->track_token) {
                $order->track_token = Str::random(32);
            }
            $order->updated_at = now()->toIso8601String();
            $order->save();
        });

        $this->queue->enqueue($order);
    }

    /** Called when an operator starts working on an order. */
    public function onStart(Order $order, ?string $operatorId = null): void
    {
        $this->queue->startOrder($order, $operatorId);
    }

    /** Called when an operator marks an order as ready. */
    public function onReady(Order $order): void
    {
        $this->queue->finishOrder($order);
    }

    /** Called when an order is cancelled. */
    public function onCancel(Order $order): void
    {
        $this->queue->dequeue($order);

        DB::transaction(function () use ($order) {
            $now = now()->toIso8601String();
            $prev = $order->status;

            $order->status = Order::STATUS_CANCELLED;
            $order->no_response_deadline = null;
            $order->updated_at = $now;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $prev,
                'to_status' => Order::STATUS_CANCELLED,
                'changed_by' => 'system',
                'changed_at' => $now,
            ]);
        });
    }

    /** Called when an order is delivered (final state). */
    public function onDelivered(Order $order): void
    {
        $this->queue->dequeue($order);

        DB::transaction(function () use ($order) {
            $now = now()->toIso8601String();
            $prev = $order->status;

            $order->status = Order::STATUS_DELIVERED;
            $order->updated_at = $now;
            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $prev,
                'to_status' => Order::STATUS_DELIVERED,
                'changed_by' => 'system',
                'changed_at' => $now,
            ]);
        });
    }
}
