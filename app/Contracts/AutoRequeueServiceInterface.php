<?php

namespace App\Contracts;

/**
 * Auto-requeue service — handles the "no response within 15 minutes" rule.
 *
 * When the operator cannot reach the customer (they press «پاسخ نداد» in the
 * admin sheet), a no_response_deadline is armed (now + 15 min). If the
 * deadline passes without the operator starting the order (the customer's
 * "answered" signal), the order is pushed to the back of the queue.
 * After MAX_NO_RESPONSE_REQUEUES such cycles the order is auto-cancelled.
 */
interface AutoRequeueServiceInterface
{
    /** How many times an order may be auto-pushed to the back before the
     * next expired deadline cancels it entirely. */
    public const MAX_NO_RESPONSE_REQUEUES = 3;

    /** Find all orders whose no_response_deadline has passed. */
    public function findExpired(): array;

    /** Handle one expired deadline: push the order to the back of the queue
     * — or, once it has already been pushed MAX_NO_RESPONSE_REQUEUES times,
     * cancel it entirely (customer/dequeue + history + notifications).
     * Orders without a queue entry or already started are skipped.
     *
     * @return string 'requeued'|'cancelled'|'skipped'|'failed'
     */
    public function requeueExpired(\App\Models\Order $order): string;

    /** Run the full sweep (called by the scheduler every minute). */
    public function sweep(): int;
}
