<?php

namespace App\Jobs;

use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Models\Order;
use App\Models\QueueEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the STORED queue ETAs honest.
 *
 * The customer's polling endpoint (/orders/{id}/updates) and the public
 * tracker recompute their ETA live on every request — but the stamps
 * persisted on the orders row (estimated_start_at / estimated_end_at)
 * were frozen at enqueue time. Everything that reads the STORED values
 * between polls sees a fossil:
 *
 *  - the order list cards (Formatter::order),
 *  - SendStartReminder («۵ دقیقه تا شروع» fires off a stale schedule),
 *  - the SMS payloads (start_time / end_time are read from the columns).
 *
 * The worst offender is the scenario this job exists for: the order
 * being worked on RIGHT NOW runs long. Its operator slot frees up at
 * max(now, its estimated end) — so the moment reality passes that end,
 * everyone behind it is effectively "next", and their old
 * «شروع تقریبی ۰۰:۴۴» quietly becomes a broken promise displayed as
 * truth.
 *
 * Every minute, for every waiting (not-yet-started) order, this job
 * re-runs the SAME live simulation the tracker uses and re-stamps:
 *
 *  - «due now» estimates (start ≤ now: the current order over-ran, the
 *    slot is free, an operator can pick it up) are ALWAYS re-written,
 *    so the claim keeps sliding with reality instead of fossilizing —
 *    the customer always sees the current minute, never a past one;
 *  - anchored future estimates are re-written only when they drift more
 *    than TOLERANCE_SECONDS from the stored stamp, so stable schedules
 *    don't churn the rows every run.
 *
 * Deliberately NOT touched: the IN-PROGRESS order's own
 * estimated_end_at. Nobody knows how much longer it will actually take
 * — fabricating a new completion time would trade an honest «دیر شده»
 * for a made-up promise. Its stamp stays where startOrder put it.
 *
 * Idempotent and self-healing: a requeue/cancel that races this job
 * simply produces one minute of slightly stale stamps, corrected on the
 * next run.
 */
class RefreshQueueEstimates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 50;
    public $tries = 1;

    /** Ignore drift below this many seconds (minute-rounding jitter). */
    public const TOLERANCE_SECONDS = 120;

    private const MAX_ORDERS = 50;

    public function handle(WaitTimeEstimatorServiceInterface $estimator): void
    {
        $waiting = QueueEntry::query()
            ->whereNull('started_at')
            ->orderBy('position')
            ->limit(self::MAX_ORDERS)
            ->get();

        if ($waiting->isEmpty()) {
            return;
        }

        $nowTs = now()->getTimestamp();
        $restamped = 0;

        foreach ($waiting as $entry) {
            $order = Order::find($entry->order_id);
            // Only genuinely queued orders carry a customer-facing ETA;
            // pending-payment rows must keep whatever the payment flow
            // will stamp when they finally submit.
            if (! $order || $order->status !== Order::STATUS_SUBMITTED) {
                continue;
            }

            $est = $estimator->estimateForOrder($order);
            if (! $est) {
                continue; // orphaned entry — compact() owns that problem
            }

            $newStartTs = strtotime((string) $est['start_at']);
            $oldStartTs = $order->estimated_start_at
                ? strtotime((string) $order->estimated_start_at)
                : false;

            // «Due now» claims expire the second they are written — keep
            // them sliding. Anchored future claims only move on real drift.
            $dueNow = $newStartTs <= $nowTs;
            $drifted = $oldStartTs === false
                || abs($newStartTs - $oldStartTs) > self::TOLERANCE_SECONDS;

            if (! $dueNow && ! $drifted) {
                continue;
            }

            $order->estimated_wait_minutes = $est['wait_minutes'];
            $order->estimated_duration_minutes = $est['duration_minutes'];
            $order->estimated_start_at = $est['start_at'];
            $order->estimated_end_at = $est['end_at'];
            $order->save();
            $restamped++;
        }

        if ($restamped > 0) {
            Log::info('RefreshQueueEstimates re-stamped waiting orders', [
                'count' => $restamped,
                'waiting' => $waiting->count(),
            ]);
        }
    }
}
