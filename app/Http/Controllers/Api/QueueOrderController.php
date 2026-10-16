<?php

namespace App\Http\Controllers\Api;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Contracts\OrderLifecycleService;
use App\Contracts\QueueManagerServiceInterface;
use App\Contracts\SlotReservationServiceInterface;
use App\Contracts\WaitTimeEstimatorServiceInterface;
use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderStarted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Queue\ReserveOrderRequest;
use App\Http\Resources\OrderUpdateResource;
use App\Models\CapacitySlot;
use App\Models\Order;
use App\Models\QueueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Queue-specific order operations.
 *
 * Existing OrderController keeps the payment-centric lifecycle
 * (creation, payment, ticket, refunds). This controller layers the
 * smart-queue operations on top:
 *  - PATCH /orders/{id}/start     → operator marks work started
 *  - PATCH /orders/{id}/complete  → operator marks work finished
 *  - POST  /orders/{id}/reserve   → reserve a capacity slot (long type)
 *  - POST  /orders/{id}/notify-me → register "notify on start"
 *  - GET   /orders/{id}/updates   → lightweight polling (rate-limited)
 *  - DELETE /orders/{id}          → cancel + dequeue
 *  - GET   /orders/track/{token}  → public tracking (no auth)
 */
class QueueOrderController extends Controller
{
    public function __construct(
        private readonly QueueManagerServiceInterface $queue,
        private readonly OrderLifecycleService $lifecycle,
        private readonly WaitTimeEstimatorServiceInterface $estimator,
        private readonly NotificationDispatcherServiceInterface $notifications,
        private readonly SlotReservationServiceInterface $slots,
    ) {}

    /** Operator starts work on an order. */
    public function start(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        $order = Order::findOrFail($id);
        if (! in_array($order->status, [Order::STATUS_SUBMITTED, Order::STATUS_PROCESSING], true)) {
            return response()->json(['error' => 'سفارش در وضعیت قابل‌شروع نیست.'], 422);
        }

        $this->lifecycle->onStart($order, $user->id);
        $order->refresh();

        OrderStarted::dispatch($order, $user->id);

        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve($request),
        ]);
    }

    /** Operator marks the order as ready. */
    public function complete(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        $order = Order::findOrFail($id);
        if ($order->status !== Order::STATUS_PROCESSING) {
            return response()->json(['error' => 'سفارش در حال انجام نیست.'], 422);
        }

        $this->lifecycle->onReady($order);
        $order->refresh();

        OrderCompleted::dispatch($order);
        $this->notifications->notifyOrderReady($order);

        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve($request),
        ]);
    }

    /** Reserve a capacity slot for a "long" type order. */
    public function reserve(ReserveOrderRequest $request, string $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $user = $request->attributes->get('authUser');

        if ($order->customer_id !== $user->id && ! in_array($user->role, ['admin', 'operator'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        $slot = CapacitySlot::findOrFail($request->validated('slot_id'));
        if (! $this->slots->reserve($slot, $order)) {
            return response()->json(['error' => 'این نوبت دیگر در دسترس نیست.'], 409);
        }

        $order->refresh();
        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve($request),
            'slot' => [
                'id' => $slot->id,
                'start_at' => $slot->start_at,
                'end_at' => $slot->end_at,
            ],
        ]);
    }

    /** "Notify me when my order starts" — sets a per-order flag in cache. */
    public function notifyMe(Request $request, string $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $user = $request->attributes->get('authUser');

        if ($order->customer_id !== $user->id) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        Cache::store('database')->put("notify-me:{$order->id}", true, now()->addDays(7));

        return response()->json([
            'ok' => true,
            'message' => 'هنگام شروع کار روی سفارش، به شما اطلاع می‌دهیم.',
        ]);
    }

    /**
     * Lightweight polling endpoint — rate-limited to 12 req/min.
     * Returns only the queue-relevant fields + recent notifications.
     */
    public function updates(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $key = 'order-updates:'.($user?->id ?? $request->ip()).":{$id}";

        if (RateLimiter::tooManyAttempts($key, 12)) {
            return response()->json(['error' => 'تعداد درخواست‌ها بیش از حد مجاز است.'], 429);
        }
        RateLimiter::hit($key, 60);

        $order = Order::findOrFail($id);

        // Owner or staff only.
        if ($order->customer_id !== ($user?->id ?? '') && ! in_array($user?->role ?? '', ['admin', 'operator'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        $notifications = $this->notifications->recentForOrder($order->id, limit: 5);

        $this->applyLiveEstimate($order);

        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve($request),
            'notifications' => $notifications,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Recompute the queue position + ETA live (in-memory only, no save) so
     * the customer's tracker reflects the queue as it is RIGHT NOW — not
     * the snapshot stamped when the order first enqueued. Without this the
     * displayed wait never shrinks while operators drain the queue.
     */
    private function applyLiveEstimate(Order $order): void
    {
        if ($order->status !== Order::STATUS_SUBMITTED) {
            return;
        }

        $est = $this->estimator->estimateForOrder($order);
        if (! $est) {
            return;
        }

        $entry = QueueEntry::where('order_id', $order->id)->first();
        $order->queue_position = $entry?->position;
        $order->estimated_wait_minutes = $est['wait_minutes'];
        $order->estimated_duration_minutes = $est['duration_minutes'];
        $order->estimated_start_at = $est['start_at'];
        $order->estimated_end_at = $est['end_at'];
    }

    /** Cancel + dequeue (customer or staff). */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $user = $request->attributes->get('authUser');

        $isStaff = in_array($user->role, ['admin', 'operator'], true);
        if ($order->customer_id !== $user->id && ! $isStaff) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        if (in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], true)) {
            return response()->json(['error' => 'این سفارش قابل لغو نیست.'], 422);
        }

        $this->lifecycle->onCancel($order);
        $order->refresh();

        OrderCancelled::dispatch($order);
        $this->notifications->notifyOrderCancelled($order);

        return response()->json([
            'order' => (new OrderUpdateResource($order))->resolve($request),
        ]);
    }
}
