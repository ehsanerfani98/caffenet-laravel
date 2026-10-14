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
