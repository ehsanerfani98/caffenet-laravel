<?php

namespace App\Contracts;

/**
 * Estimates how long a NEW order will wait before work starts, plus the
 * duration of work itself. The two are kept SEPARATE (per the spec) so
 * the UI can show:
 *
 *   ⏳ انتظار تقریبی: 35 دقیقه
 *   ⚙️ مدت انجام تقریبی: 20 دقیقه
 *   ───────────────────────────────
 *   ⏱ زمان تقریبی دریافت خدمت: 55 دقیقه
 *
 * Estimates use the order_type.base_duration_minutes averaged across
 * the queue, plus a per-operator capacity factor.
 */
interface WaitTimeEstimatorServiceInterface
{
    /** Sum of estimated durations of all orders currently ahead in the queue. */
    public function estimateWaitMinutes(?string $queueType = null): int;

    /** Look up or default the duration for a given queue type. */
    public function estimateDurationMinutes(string $queueType): int;

    /** Total = wait + duration. Convenience for the UI. */
    public function estimateTotalMinutes(?string $queueType = null): int;

    /** ISO 8601 timestamp for when work on a new order would START. */
    public function estimateStartAt(?string $queueType = null): string;

    /** ISO 8601 timestamp for when work on a new order would FINISH. */
    public function estimateEndAt(?string $queueType = null): string;
}
