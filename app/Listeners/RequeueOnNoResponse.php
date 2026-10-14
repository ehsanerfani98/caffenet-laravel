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
            $this->autoRequeue->requeueExpired($event->order);
            $this->notifications->notifyOrderRequeued($event->order);
        } catch (\Throwable $e) {
            Log::error('RequeueOnNoResponse failed', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
