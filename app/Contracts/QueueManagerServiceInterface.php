<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Manages the in-memory-on-disk queue: adding, removing, repositioning
 * orders; requeuing no-response orders; producing the snapshot the admin
 * dashboard polls.
 */
interface QueueManagerServiceInterface
{
    /**
     * Add an order to the queue. Position = current_max + 1.
     * Sets queue_position on the order; creates a QueueEntry row.
     * Must run inside a queue:position lock.
     */
    public function enqueue(Order $order): void;

    /**
     * Remove an order from the queue (cancel / deliver). Repacks positions
     * so they stay 1..N contiguous. Must run inside a queue:position lock.
     */
    public function dequeue(Order $order): void;

    /** Mark an order as started: set started_at, transition to processing. */
    public function startOrder(Order $order, ?string $operatorId = null): void;

    /** Mark an order as finished: set finished_at, transition to ready. */
    public function finishOrder(Order $order): void;

    /**
     * Push a no-response order to the back of the queue. Increments
     * requeue_count and resets no_response_deadline to null.
     */
    public function requeue(Order $order): void;

    /** All queue entries ordered by position (with their orders). */
    public function snapshot(): array;

    /** Count of orders currently waiting (excluding the one being processed). */
    public function waitingCount(): int;
}
