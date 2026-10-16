<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Queue lifecycle orchestrator contract.
 *
 * Single entry point controllers call when moving an order between
 * queue-relevant states. Implementations guarantee:
 *  - Status history is always written.
 *  - queue_entries rows are created/updated/removed in lockstep with the
 *    order status.
 *  - Estimated times are (re)computed on enqueue.
 *  - track_token is generated on first enqueue.
 */
interface OrderLifecycleService
{
    /** Order became `submitted` (post-payment): stamp ETA + enqueue. */
    public function onSubmitted(Order $order): void;

    /** Operator started working on the order. */
    public function onStart(Order $order, ?string $operatorId = null): void;

    /** Operator marked the order ready. */
    public function onReady(Order $order): void;

    /** Order cancelled: dequeue + mark cancelled. */
    public function onCancel(Order $order): void;

    /** Order delivered (final state): dequeue + mark delivered. */
    public function onDelivered(Order $order): void;
}
