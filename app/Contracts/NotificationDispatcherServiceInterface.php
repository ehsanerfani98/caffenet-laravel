<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Notification dispatcher — writes rows to notification_logs and emits
 * in-app pings. The frontend polls /api/orders/{id}/updates and surfaces
 * these as toasts / browser notifications.
 *
 * Per spec: NO SMS, NO Telegram, NO WhatsApp, NO Firebase. The audit log
 * IS the notification layer.
 */
interface NotificationDispatcherServiceInterface
{
    /** Notify that work has started on the order. */
    public function notifyOrderStarted(Order $order): void;

    /** Notify that the order is ready for delivery/download. */
    public function notifyOrderReady(Order $order): void;

    /** Notify that the order was requeued (no-response). */
    public function notifyOrderRequeued(Order $order): void;

    /** Notify that the order was cancelled. */
    public function notifyOrderCancelled(Order $order): void;

    /** Send a reminder $minutesBefore the estimated start. */
    public function sendStartReminder(Order $order, int $minutesBefore = 5): void;

    /** Fetch the latest N notifications for an order (for polling). */
    public function recentForOrder(string $orderId, int $limit = 10): array;
}
