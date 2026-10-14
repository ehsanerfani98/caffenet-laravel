<?php

namespace App\Services\Notification;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Writes rows to notification_logs (the single source of truth for
 * in-app notifications) and is the audit trail for every queue event.
 *
 * The frontend polls /api/orders/{id}/updates and surfaces these as
 * toasts / browser notifications. No third-party channels are wired up
 * (per spec: no SMS, no Telegram, no Firebase).
 */
class NotificationDispatcherService implements NotificationDispatcherServiceInterface
{
    public function notifyOrderStarted(Order $order): void
    {
        $this->write($order, NotificationLog::TYPE_ORDER_STARTED, [
            'title' => 'کار روی سفارش شما شروع شد',
            'body' => "سفارش {$order->order_number} وارد مرحله انجام شد.",
            'order_number' => $order->order_number,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    public function notifyOrderReady(Order $order): void
    {
        $this->write($order, NotificationLog::TYPE_ORDER_READY, [
            'title' => 'سفارش شما آماده است',
            'body' => "سفارش {$order->order_number} آماده تحویل / دانلود است.",
            'order_number' => $order->order_number,
            'ready_at' => now()->toIso8601String(),
        ]);
    }

    public function notifyOrderRequeued(Order $order): void
    {
        $this->write($order, NotificationLog::TYPE_REQUEUED, [
            'title' => 'نوبت شما به انتهای صف منتقل شد',
            'body' => 'به دلیل عدم پاسخ‌گویی در زمان مقرر، سفارش شما به انتهای صف منتقل شد.',
            'order_number' => $order->order_number,
            'new_position' => $order->queue_position,
        ]);
    }

    public function notifyOrderCancelled(Order $order): void
    {
        $this->write($order, NotificationLog::TYPE_CANCELLED, [
            'title' => 'سفارش لغو شد',
            'body' => "سفارش {$order->order_number} لغو شد.",
            'order_number' => $order->order_number,
        ]);
    }

    public function sendStartReminder(Order $order, int $minutesBefore = 5): void
    {
        $this->write($order, NotificationLog::TYPE_REMINDER, [
            'title' => "{$minutesBefore} دقیقه تا شروع سفارش شما",
            'body' => "سفارش {$order->order_number} به‌زودی شروع می‌شود. آماده باشید.",
            'order_number' => $order->order_number,
            'minutes_before' => $minutesBefore,
        ]);
    }

    public function recentForOrder(string $orderId, int $limit = 10): array
    {
        return NotificationLog::where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'channel' => $n->channel,
                'payload' => json_decode((string) $n->payload, true) ?: [],
                'status' => $n->status,
                'sent_at' => $n->sent_at,
                'created_at' => $n->created_at,
            ])
            ->all();
    }

    private function write(Order $order, string $type, array $payload): void
    {
        try {
            NotificationLog::create([
                'order_id' => $order->id,
                'user_id' => $order->customer_id,
                'type' => $type,
                'channel' => NotificationLog::CHANNEL_IN_APP,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'status' => 'sent',
                'sent_at' => now()->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('NotificationDispatcher: write failed', [
                'order_id' => $order->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
