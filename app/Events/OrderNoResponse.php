<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when the 15-minute no-response deadline passes for an order
 * at the front of the queue.
 *
 * Listeners:
 *  - RequeueOnNoResponse → calls AutoRequeueService::requeueExpired()
 */
class OrderNoResponse
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
