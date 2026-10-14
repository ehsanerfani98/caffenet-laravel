<?php

namespace App\Listeners;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Events\OrderStarted;
use Illuminate\Support\Facades\Log;

/**
 * Writes the in-app "your order has started" notification when an order
 * transitions to processing.
 */
class LogNotificationOnStart
{
    public function __construct(
        private readonly NotificationDispatcherServiceInterface $notifications,
    ) {}

    public function handle(OrderStarted $event): void
    {
        try {
            $this->notifications->notifyOrderStarted($event->order);
        } catch (\Throwable $e) {
            Log::error('LogNotificationOnStart failed', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
