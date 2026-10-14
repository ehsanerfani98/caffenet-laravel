<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Snapshot of the global capacity state — used by the public
 * /api/capacity/current endpoint that drives the top-of-page indicator
 * (🟢 / 🟡 / 🟠 / 🔴).
 */
class CapacityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tier' => $this->resource['tier'] ?? 'green',
            'title' => $this->resource['title'] ?? 'ظرفیت عادی',
            'eta_minutes' => $this->resource['eta_minutes'] ?? 0,
            'first_slot_at' => $this->resource['first_slot_at'] ?? null,
            'updated_at' => $this->resource['updated_at'] ?? now()->toIso8601String(),
        ];
    }
}
