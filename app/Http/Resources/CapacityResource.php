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
    /**
     * No `{"data": …}` envelope. The client (public/js/queue.js Poller)
     * reads `data.tier`, `data.eta_minutes`… straight off the response
     * body, and QueueApiTest asserts the same top-level shape — with the
     * default Laravel wrapping every response looked like
     * `{"data": {…}}` and the indicator silently fell back to 🟢 green.
     */
    public static $wrap = false;

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
