<?php

namespace App\Services\Queue;

use App\Contracts\AutoRequeueServiceInterface;
use App\Contracts\NotificationDispatcherServiceInterface;
use App\Contracts\OrderLifecycleService;
use App\Contracts\QueueManagerServiceInterface;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-requeue sweep — runs every minute via the scheduler.
 *
 * Finds QueueEntry rows whose no_response_deadline (armed by the operator's
 * «پاسخ نداد» action) has passed and pushes the underlying order to the back
 * of the queue via QueueManagerService. The operator pressing «شروع کار» is
 * what counts as the customer having answered: it clears the deadline, so a
 * started order is never swept.
 *
 * Three-strike rule: once an order has been auto-pushed to the back
 * MAX_NO_RESPONSE_REQUEUES times, the NEXT expired deadline cancels the
 * order entirely (same path as the manual cancel: dequeue + history +
 * OrderCancelled event + cancel SMS/push).
 *
 * Idempotent: once handled, no_response_deadline is nulled, so the same row
 * is never picked up twice by the same sweep.
 */
class AutoRequeueService implements AutoRequeueServiceInterface
{
    public function __construct(
        private readonly QueueManagerServiceInterface $queue,
        private readonly NotificationDispatcherServiceInterface $notifications,
    ) {}

    public function findExpired(): array
    {
        $now = now()->toIso8601String();
        $rows = DB::table('queue_entries')
            ->whereNotNull('no_response_deadline')
            ->where('no_response_deadline', '<', $now)
            // A started order means the customer answered — never strike it,
            // even if a stale deadline somehow survived (belt and braces:
            // QueueManagerService::startOrder already clears it).
            ->whereNull('started_at')
            ->limit(50)
            ->get();

        return $rows->map(fn ($r) => Order::find($r->order_id))->filter()->all();
    }

    public function requeueExpired(Order $order): string
    {
        try {
            $entry = QueueEntry::where('order_id', $order->id)->first();

            // Not in the queue, or already started (customer answered) —
            // never strike those, even if a stale deadline survived.
            if (! $entry || $entry->started_at) {
                return 'skipped';
            }

            $requeues = (int) $entry->requeue_count;

            if ($requeues >= self::MAX_NO_RESPONSE_REQUEUES) {
                // Third strike: fully cancel, mirroring the manual cancel
                // path (dequeue + status history + event + notifications).
                app(OrderLifecycleService::class)->onCancel($order);
                $order->refresh();

                OrderCancelled::dispatch($order);
                $this->notifications->notifyOrderCancelled($order);

                Log::info('AutoRequeue: order auto-cancelled after 3 unanswered 15-min deadlines', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return 'cancelled';
            }

            $this->queue->requeue($order);

            Log::info('AutoRequeue: pushed order to back of queue', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'requeue_count' => $requeues + 1,
            ]);

            return 'requeued';
        } catch (\Throwable $e) {
            Log::error('AutoRequeue: failed to handle expired no-response deadline', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
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
