<?php

namespace App\Services\Notification;

use App\Contracts\NotificationDispatcherServiceInterface;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * Writes rows to notification_logs (the single source of truth for
 * in-app notifications) AND dispatches a Firebase Cloud Messaging push
 * notification when FCM is enabled in the system settings.
 *
 * The frontend polls /api/orders/{id}/updates and surfaces these as
 * toasts / browser notifications. FCM adds real push notifications that
 * arrive even when the browser tab is closed.
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
        // 1) Always write the in-app notification log row (audit trail +
        //    source for the polling endpoint).
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

        // 2) Fire a Firebase Cloud Messaging push notification when enabled.
        //    Wrapped in try/catch so a FCM outage never breaks the order flow.
        if (SettingsService::bool('fcmEnabled') && ! empty($order->customer_id)) {
            try {
                FirebasePushService::sendToUser($order->customer_id, [
                    'title' => $payload['title'] ?? '',
                    'body' => $payload['body'] ?? '',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'type' => $type,
                    ],
                    'url' => '/?order=' . $order->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('NotificationDispatcher: FCM push failed', [
                    'order_id' => $order->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3) Send an SMS notification — ALL pattern-based.
        //    Only fires when the admin has enabled smsNotifyEnabled AND the
        //    order has a valid customer phone.
        if (SettingsService::bool('smsNotifyEnabled') && ! empty($order->customer_phone)) {
            try {
                $eventType = $this->eventTypeForType($type);
                $data = self::orderDataPool($order, $payload);
                SmsService::sendOrderNotification(
                    $order->customer_phone,
                    $eventType,
                    $data
                );
            } catch (\Throwable $e) {
                Log::error('NotificationDispatcher: SMS send failed', [
                    'order_id' => $order->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Map an internal notification type (NotificationLog::TYPE_*) to the
     * event-type key used by SmsService::sendOrderNotification.
     */
    private function eventTypeForType(string $type): string
    {
        return [
            NotificationLog::TYPE_ORDER_STARTED => 'order_started',
            NotificationLog::TYPE_ORDER_READY   => 'order_ready',
            NotificationLog::TYPE_REQUEUED      => 'requeued',
            NotificationLog::TYPE_CANCELLED     => 'cancelled',
            NotificationLog::TYPE_REMINDER      => 'reminder',
        ][$type] ?? 'order_status';
    }

    /**
     * Build the data pool for an order notification.
     *
     * Produces values for ALL internal data identifiers declared in
     * SmsTypeRegistry::DATA_LABELS. The admin's variable mapping
     * (in sms_pattern_vars) connects these identifiers to the variable
     * names they used in IPPanel.
     */
    private static function orderDataPool(Order $order, array $payload): array
    {
        $data = [
            'order_number'     => $order->order_number,
            'customer_name'    => $order->customer_name,
            'customer_phone'   => $order->customer_phone,
            'service_title'    => $order->service_title,
            'status'           => $order->status,
            'status_title'     => \App\Models\Order::STATUS_TITLES[$order->status] ?? $order->status,
            'eta_minutes'      => (string) ($order->estimated_wait_minutes ?? ''),
            'duration_minutes' => (string) ($order->estimated_duration_minutes ?? ''),
            'start_time'       => (string) ($order->estimated_start_at ?? ''),
            'end_time'         => (string) ($order->estimated_end_at ?? ''),
            'queue_position'   => (string) ($order->queue_position ?? ''),
            'tracking_url'     => $order->track_token
                ? url('/api/orders/track/'.$order->track_token)
                : '',
            'amount'           => (string) max(0, $order->paid_amount),
            'payment_method'   => $order->payment_method,
            'site_title'       => SettingsService::get('siteTitle', 'کافی‌نت احسان'),
        ];
        // Merge extra payload fields (e.g. minutes_before, title, body).
        foreach ($payload as $k => $v) {
            if (is_scalar($v) && ! isset($data[$k])) {
                $data[$k] = (string) $v;
            }
        }
        return $data;
    }
}
