<?php

namespace App\Contracts;

/**
 * Resolves the global capacity tier shown at the top of the site:
 *
 *   🟢 ظرفیت عادی                       ETA < 15 min
 *   🟡 صف در حال افزایش — شروع ~30 دقیقه 15-30 min
 *   🟠 صف شلوغ — شروع ۱ تا ۲ ساعت        30-60 min
 *   🔴 پذیرش امروز تکمیل شده             no slot today
 *
 * Used both by the public /api/capacity/current endpoint and by the
 * admin dashboard's compact status pill.
 */
interface StatusResolverServiceInterface
{
    /** One of Order::CAPACITY_* (green/yellow/orange/red). */
    public function currentTier(): string;

    /** Persian title for the current tier. */
    public function currentTitle(): string;

    /** ETA in minutes for a new order (0 if no capacity today). */
    public function currentEtaMinutes(): int;

    /** Compact array: ['tier' => ..., 'title' => ..., 'eta_minutes' => ..., 'first_slot_at' => ?string]. */
    public function snapshot(): array;
}
