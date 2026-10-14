<?php

namespace App\Services\Queue;

use App\Contracts\AutoRequeueServiceInterface;
use App\Contracts\QueueManagerServiceInterface;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-requeue sweep — runs every minute via the scheduler.
 *
 * Finds QueueEntry rows whose no_response_deadline has passed and pushes
 * the underlying order to the back of the queue via QueueManagerService.
 *
 * Idempotent: once requeued, no_response_deadline is nulled, so the same
 * row is never picked up twice by the same sweep.
 */
class AutoRequeueService implements AutoRequeueServiceInterface
{
    public function __construct(
        private readonly QueueManagerServiceInterface $queue,
    ) {}

    public function findExpired(): array
    {
        $now = now()->toIso8601String();
        $rows = DB::table('queue_entries')
            ->whereNotNull('no_response_deadline')
            ->where('no_response_deadline', '<', $now)
            ->limit(50)
            ->get();

        return $rows->map(fn ($r) => Order::find($r->order_id))->filter()->all();
    }

    public function requeueExpired(Order $order): void
    {
        try {
            $this->queue->requeue($order);
            Log::info('AutoRequeue: pushed order to back of queue', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Throwable $e) {
            Log::error('AutoRequeue: failed to requeue order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sweep(): int
    {
        $expired = $this->findExpired();
        foreach ($expired as $order) {
            $this->requeueExpired($order);
        }
        return count($expired);
    }
}
