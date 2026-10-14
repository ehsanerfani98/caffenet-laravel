<?php

namespace App\Services\Queue;

use App\Models\OrderType;

/**
 * Maps a service's `estimated_time` (or any free-form text) to one of the
 * three queue types: quick / normal / long.
 *
 * Heuristics:
 *  - "5 دقیقه" or numeric ≤ 10  → quick
 *  - "30 دقیقه" or numeric ≤ 45  → normal
 *  - anything larger or "ساعتی"  → long
 *
 * Falls back to `normal` when the input is empty or unparseable.
 */
class OrderTypeClassifierService
{
    public function classify(?string $estimatedTimeText, ?string $fallbackKey = null): string
    {
        $text = trim((string) $estimatedTimeText);
        if ($text === '') {
            return $fallbackKey ?: OrderType::KEY_NORMAL;
        }

        // Look for an explicit numeric minute/hour value.
        if (preg_match('/(\d+)\s*(دقیقه|min)/iu', $text, $m)) {
            $mins = (int) $m[1];
            return $this->fromMinutes($mins);
        }
        if (preg_match('/(\d+)\s*(ساعت|hour)/iu', $text, $m)) {
            $hours = (int) $m[1];
            return $hours >= 1 ? OrderType::KEY_LONG : OrderType::KEY_NORMAL;
        }
        if (preg_match('/(\d+)/', $text, $m)) {
            return $this->fromMinutes((int) $m[1]);
        }

        // Keyword fallbacks.
        if (str_contains($text, 'فوری') || str_contains($text, 'سریع')) {
            return OrderType::KEY_QUICK;
        }
        if (str_contains($text, 'ساعتی') || str_contains($text, 'زمان‌بر')) {
            return OrderType::KEY_LONG;
        }

        return $fallbackKey ?: OrderType::KEY_NORMAL;
    }

    private function fromMinutes(int $mins): string
    {
        if ($mins <= 10) {
            return OrderType::KEY_QUICK;
        }
        if ($mins <= 45) {
            return OrderType::KEY_NORMAL;
        }
        return OrderType::KEY_LONG;
    }
}
