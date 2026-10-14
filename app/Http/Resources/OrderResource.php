<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact view of an order for queue/tracking endpoints.
 *
 * Returns only the fields the queue UI needs — payment internals are
 * intentionally excluded. For the full order, see OrderController::show().
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'service_title' => $this->service_title,
            'customer_name' => $this->customer_name,
            'status' => $this->status,
            'status_title' => \App\Models\Order::STATUS_TITLES[$this->status] ?? null,
            'queue_type' => $this->queue_type,
            'queue_position' => $this->queue_position,
            'priority_level' => $this->priority_level,
            'estimated_wait_minutes' => $this->estimated_wait_minutes,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'estimated_start_at' => $this->estimated_start_at,
            'estimated_end_at' => $this->estimated_end_at,
            'no_response_deadline' => $this->no_response_deadline,
            'track_token' => $this->track_token,
            'assigned_operator_id' => $this->assigned_operator_id,
            'assigned_operator_name' => $this->assigned_operator_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
