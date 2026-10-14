<?php

namespace App\Jobs;

use App\Contracts\AutoRequeueServiceInterface;
use App\Events\OrderNoResponse;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every minute by the console kernel.
 *
 * Finds orders whose no_response_deadline has passed and dispatches the
 * OrderNoResponse event for each. The RequeueOnNoResponse listener does
 * the actual requeue.
 *
 * Idempotent: once requeued, no_response_deadline is nulled, so the same
 * order won't be picked up again.
 *
 * Designed for the cPanel cron environment:
 *  - $timeout = 50s (matches the queue:work --max-time=50 cap)
 *  - $tries = 1 (no automatic retries; the next minute's run handles it)
 */
class CheckNoResponseOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 50;
    public $tries = 1;

    public function handle(AutoRequeueServiceInterface $autoRequeue): void
    {
        $expired = $autoRequeue->findExpired();
        foreach ($expired as $order) {
            try {
                OrderNoResponse::dispatch($order);
            } catch (\Throwable $e) {
                Log::error('CheckNoResponseOrders: dispatch failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (count($expired) > 0) {
            Log::info('CheckNoResponseOrders: dispatched events', [
                'count' => count($expired),
            ]);
        }
    }
}
