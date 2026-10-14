<?php

namespace App\Contracts;

/**
 * Auto-requeue service — handles the "no response within 15 minutes" rule.
 *
 * When an order reaches the front of the queue (position 1) and the
 * operator signals the customer to provide details / approve the work,
 * a no_response_deadline is set (now + 15 min). If the deadline passes
 * without action, the order is pushed to the back of the queue.
 */
interface AutoRequeueServiceInterface
{
    /** Find all orders whose no_response_deadline has passed. */
    public function findExpired(): array;

    /** Push a single expired order to the back of the queue. */
    public function requeueExpired(\App\Models\Order $order): void;

    /** Run the full sweep (called by the scheduler every minute). */
    public function sweep(): int;
}
