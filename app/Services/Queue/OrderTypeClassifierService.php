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
        // Normalize Persian (۱ ساعت) / Arabic (١ ساعت) digits to ASCII first —
        // PCRE's \d only matches ASCII, so «۹۰ دقیقه» used to slip through
        // every numeric branch and fall back to normal.
        $text = trim($this->normalizeDigits((string) $estimatedTimeText));
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

    /** Persian (۰-۹) and Arabic (٠-٩) digits → ASCII. */
    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
