<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight order-update payload for the polling endpoint
 * /api/orders/{id}/updates. Designed to be small so 15-second polling
 * stays cheap on shared hosting.
 */
class OrderUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_title' => \App\Models\Order::STATUS_TITLES[$this->status] ?? null,
            'queue_position' => $this->queue_position,
            'estimated_wait_minutes' => $this->estimated_wait_minutes,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'estimated_start_at' => $this->estimated_start_at,
            'estimated_end_at' => $this->estimated_end_at,
            'no_response_deadline' => $this->no_response_deadline,
            'assigned_operator_name' => $this->assigned_operator_name,
            'updated_at' => $this->updated_at,
        ];
    }
}
