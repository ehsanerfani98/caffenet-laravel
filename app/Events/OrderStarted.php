<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an operator starts working on an order (submitted → processing).
 *
 * Listeners:
 *  - LogNotificationOnStart → writes the "car rooy-e sefaresh-e shoma shoro shod"
 *    row to notification_logs (the in-app notification layer).
 */
class OrderStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly ?string $operatorId = null,
    ) {}
}
