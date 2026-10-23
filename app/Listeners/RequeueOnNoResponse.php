<?php

namespace App\Listeners;

use App\Contracts\AutoRequeueServiceInterface;
use App\Contracts\NotificationDispatcherServiceInterface;
use App\Events\OrderNoResponse;
use Illuminate\Support\Facades\Log;

class RequeueOnNoResponse
{
    public function __construct(
        private readonly AutoRequeueServiceInterface $autoRequeue,
        private readonly NotificationDispatcherServiceInterface $notifications,
    ) {}

    public function handle(OrderNoResponse $event): void
    {
        try {
            $outcome = $this->autoRequeue->requeueExpired($event->order);

            if ($outcome === 'cancelled') {
                // notifyOrderCancelled already ran inside the service —
                // nothing extra to send here.
                return;
            }

            if ($outcome === 'requeued') {
                $this->notifications->notifyOrderRequeued($event->order);
            }
            // 'skipped' needs nothing; 'failed' is already logged by the service.
        } catch (\Throwable $e) {
            Log::error('RequeueOnNoResponse failed', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
