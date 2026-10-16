<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderUpdateResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Public order tracking — accessible without login via a long random
 * track_token. Returns the same lightweight payload as the polling
 * endpoint, plus the recent notifications for the order.
 *
 * This is the URL a customer can share with themselves / open on a
 * second device. The token is generated once on order submission.
 */
class TrackController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $order = Order::where('track_token', $token)->first();
        if (! $order) {
            return response()->json(['error' => 'سفارش یافت نشد.'], 404);
        }

        // Live ETA — same contract as /orders/{id}/updates: the public
        // tracking page must show the queue as it is now, not the snapshot
        // from enqueue time.
        if ($order->status === \App\Models\Order::STATUS_SUBMITTED) {
            $est = app(\App\Contracts\WaitTimeEstimatorServiceInterface::class)->estimateForOrder($order);
            if ($est) {
                $entry = \App\Models\QueueEntry::where('order_id', $order->id)->first();
                $order->queue_position = $entry?->position;
                $order->estimated_wait_minutes = $est['wait_minutes'];
                $order->estimated_duration_minutes = $est['duration_minutes'];
                $order->estimated_start_at = $est['start_at'];
                $order->estimated_end_at = $est['end_at'];
            }
        }

        $notifications = $order->notifications()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'payload' => json_decode((string) $n->payload, true) ?: [],
                'created_at' => $n->created_at,
            ])
            ->all();

        $history = $order->statusHistories()
            ->orderBy('changed_at')
            ->limit(30)
            ->get()
            ->map(fn ($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'changed_at' => $h->changed_at,
                'note' => $h->note,
            ])
            ->all();

        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve(request()),
            'order_number' => $order->order_number,
            'service_title' => $order->service_title,
            'notifications' => $notifications,
            'history' => $history,
        ]);
    }
}
