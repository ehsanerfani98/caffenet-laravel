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
 * Grace-wave scheduler for the smart queue.
 *
 * Contract (owner-specified):
 *
 *  1. TRIGGER — the job compares NOW with the estimated completion of
 *     every IN-PROGRESS order. When now has passed an order's
 *     estimated_end_at (the order is running long), the trigger fires.
 *  2. ACTION, exactly ONCE per occurrence — the over-running order's own
 *     completion estimate is re-stamped to NOW + GRACE_MINUTES, and every
 *     waiting order behind it is pushed to the re-anchored chain (i.e.
 *     each blocked order's «شروع تقریبی» slides ~GRACE_MINUTES later).
 *  3. WAIT — the extension itself clears the condition (end estimate is
 *     in the future again). Nothing is rewritten until NOW passes the
 *     NEW end estimate, then another +GRACE wave fires. So a badly
 *     over-running order produces one clean +20-minute push every 20
 *     minutes — never a per-minute drift.
 *
 * Two secondary passes keep the stored stamps honest WITHOUT touching
 * the wave cadence:
 *
 *  - Structural correction: when the queue speeds up for a reason the
 *    wave can't express (an order ahead was cancelled / finished early),
 *    a waiting order whose stored stamp drifted >TOLERANCE_SECONDS from
 *    the live simulation is re-anchored. This only ever moves times
 *    EARLIER or re-anchors a genuinely stale schedule — it never fires
 *    while a wave is running.
 *  - Due-now freeze: a stamp that already says "startable now" is left
 *    alone for DUE_NOW_FREEZE_SECONDS so an idle queue doesn't rewrite
 *    rows every minute; after that it is re-anchored (fossil hygiene).
 *
 * The IN-PROGRESS order's estimated_start_at (its real start) is never
 * rewritten — only its completion estimate participates in the wave.
 *
 * Idempotent: re-running the same minute produces zero writes.
 */
class RefreshQueueEstimates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 50;
    public $tries = 1;

    /** Grace minutes added to everyone's schedule each time the trigger fires. */
    public const GRACE_MINUTES = 20;

    /** Ignore drift below this many seconds (minute-rounding jitter). */
    public const TOLERANCE_SECONDS = 120;

    /** A "startable now" stamp stays untouched for this many seconds. */
    public const DUE_NOW_FREEZE_SECONDS = 600;

    private const MAX_ORDERS = 50;

    public function handle(WaitTimeEstimatorServiceInterface $estimator): void
    {
        $nowTs = now()->getTimestamp();

        // ── 1. The grace trigger ─────────────────────────────────────
        // Any in-progress order whose promised completion is already in
        // the past gets NOW + GRACE_MINUTES as its new promise.
        $extended = 0;
        $inProgress = QueueEntry::query()
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->orderBy('position')
            ->limit(self::MAX_ORDERS)
            ->get();

        foreach ($inProgress as $entry) {
            $order = Order::find($entry->order_id);
            if (! $order) {
                continue;
            }

            $endTs = $order->estimated_end_at
                ? strtotime((string) $order->estimated_end_at)
                : false;
            if ($endTs === false || $endTs >= $nowTs) {
                continue; // condition not met: completion estimate still holds
            }

            $order->estimated_end_at = now()
                ->addMinutes(self::GRACE_MINUTES)
                ->toIso8601String();
            $order->save();
            $extended++;

            Log::info('RefreshQueueEstimates grace extension', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'grace_minutes' => self::GRACE_MINUTES,
                'new_end_at' => (string) $order->estimated_end_at,
            ]);
        }

        if ($extended > 0) {
            // ── 2. The once-per-occurrence wave ──────────────────────
            // Re-anchor EVERY waiting order to the extended chain. The
            // estimator slots the grace-extended ends in, so blocked
            // orders slide ~+GRACE later while orders served by a free
            // second operator stay put.
            $restamped = $this->restampWaitingOrders($estimator, $nowTs, force: true);
            Log::info('RefreshQueueEstimates grace wave re-stamped the queue', [
                'extended_orders' => $extended,
                'restamped_waiting' => $restamped,
            ]);

            return;
        }

        // ── 3. Structural correction (no wave this run) ──────────────
        $this->restampWaitingOrders($estimator, $nowTs, force: false);
    }

    /**
     * Re-stamp waiting orders from the live simulation.
     *
     * Force mode (grace wave): every SUBMITTED waiting order is written.
     * Drift mode: only when the stored start stamp lies more than
     * TOLERANCE_SECONDS from the simulation, and never when both the
     * stored and the fresh stamps already say "startable now" within the
     * freeze window.
     *
     * @return int number of orders actually written
     */
    private function restampWaitingOrders(
        WaitTimeEstimatorServiceInterface $estimator,
        int $nowTs,
        bool $force,
    ): int {
        $waiting = QueueEntry::query()
            ->whereNull('started_at')
            ->orderBy('position')
            ->limit(self::MAX_ORDERS)
            ->get();

        $written = 0;

        foreach ($waiting as $entry) {
            $order = Order::find($entry->order_id);
            // Only genuinely queued orders carry a customer-facing ETA;
            // pending-payment rows keep whatever the payment flow will
            // stamp when they finally submit.
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

            if (! $force) {
                if ($oldStartTs !== false) {
                    $diff = abs($newStartTs - $oldStartTs);

                    // Both stamps already say "startable now" and the
                    // claim is fresh enough — freeze it, write nothing.
                    $bothDueNow = $newStartTs <= $nowTs + 60
                        && $oldStartTs <= $nowTs + 60;
                    if ($bothDueNow && $diff <= self::DUE_NOW_FREEZE_SECONDS) {
                        continue;
                    }

                    // Anchored future schedule, still true — leave it.
                    if ($diff <= self::TOLERANCE_SECONDS) {
                        continue;
                    }
                }
            }

            $order->estimated_wait_minutes = $est['wait_minutes'];
            $order->estimated_duration_minutes = $est['duration_minutes'];
            $order->estimated_start_at = $est['start_at'];
            $order->estimated_end_at = $est['end_at'];
            $order->save();
            $written++;
        }

        return $written;
    }
}
