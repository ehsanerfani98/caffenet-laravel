<?php

namespace App\Http\Support;

use App\Models\Order;
use App\Models\User;

/**
 * Row → camelCase JSON mappers preserving the EXACT response contract of the
 * original Express API (routes.ts: mapUser / mapServiceRow / formatOrder).
 */
class Formatter
{
    public static function user(User $u): array
    {
        return [
            'id' => $u->id,
            'phone' => $u->phone,
            'name' => $u->name,
            'role' => $u->role,
            'permissions' => $u->effectivePermissions(),
            'walletBalance' => (int) $u->wallet_balance,
            'createdAt' => $u->created_at,
            'isBlocked' => (bool) $u->is_blocked,
        ];
    }

    public static function service($row): array
    {
        return [
            'id' => $row->id,
            'categoryId' => $row->category_id,
            'title' => $row->title,
            'description' => (string) ($row->description ?? ''),
            'basePrice' => (int) $row->base_price,
            'estimatedTime' => (string) ($row->estimated_time ?? ''),
            'icon' => (string) ($row->icon ?? ''),
            'iconColor' => (string) ($row->icon_color ?? ''),
            'sortOrder' => (int) ($row->sort_order ?? 0),
            'isActive' => (bool) $row->is_active,
            'isFeatured' => (bool) ($row->is_featured ?? false),
            'featuredSort' => (int) ($row->featured_sort ?? 0),
            'fields' => $row->fields(),
            'pricingFormulaDescription' => (string) ($row->pricing_formula ?? ''),
        ];
    }

    public static function category($row): array
    {
        return [
            'id' => $row->id,
            'title' => $row->title,
            'iconName' => $row->icon_name,
            'iconColor' => (string) ($row->icon_color ?? ''),
            'sortOrder' => (int) ($row->sort_order ?? 0),
            'description' => (string) ($row->description ?? ''),
            'createdAt' => $row->created_at ?? null,
        ];
    }

    public static function coupon($row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'percent' => (int) $row->percent,
            'maxAmount' => (int) $row->max_amount,
            'minOrderAmount' => (int) $row->min_order_amount,
            'expireDate' => $row->expire_date,
            'usageCount' => (int) $row->usage_count,
            'maxUsage' => (int) $row->max_usage,
            'isActive' => (bool) $row->is_active,
        ];
    }

    public static function slider($row): array
    {
        return [
            'id' => $row->id,
            'imageUrl' => $row->image_url,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'ctaLabel' => $row->cta_label,
            'ctaTarget' => $row->cta_target,
            'isActive' => (bool) $row->is_active,
            'sortOrder' => (int) $row->sort_order,
            'createdAt' => $row->created_at,
        ];
    }

    public static function smsLog($row): array
    {
        return [
            'id' => $row->id,
            'phone' => $row->phone,
            'text' => $row->text,
            'type' => $row->type,
            'status' => $row->status,
            'providerMessageId' => $row->provider_message_id,
            'error' => $row->error,
            'timestamp' => $row->timestamp,
        ];
    }

    public static function orderLog($row): array
    {
        return [
            'id' => $row->id,
            'timestamp' => $row->timestamp,
            'action' => $row->action,
            'performedBy' => $row->performed_by,
            'note' => $row->note,
        ];
    }

    public static function ticketMessage($row): array
    {
        return [
            'id' => $row->id,
            'senderRole' => $row->sender_role,
            'senderName' => $row->sender_name,
            'message' => $row->message,
            'timestamp' => $row->timestamp,
            'isOperatorResponse' => (bool) $row->is_operator_response,
        ];
    }

    public static function walletTransaction($row): array
    {
        return [
            'id' => $row->id,
            'userId' => $row->user_id,
            'orderId' => $row->order_id,
            'paymentId' => $row->payment_id,
            'type' => $row->type,
            'typeTitle' => method_exists($row, 'typeTitle')
                ? $row->typeTitle()
                : (\App\Models\WalletTransaction::TYPE_TITLES[$row->type] ?? $row->type),
            'direction' => $row->direction,
            'amount' => (int) $row->amount,
            'balanceAfter' => (int) $row->balance_after,
            'reference' => $row->reference,
            'note' => $row->note,
            'performedById' => $row->performed_by_id,
            'performedByName' => $row->performed_by_name,
            'createdAt' => $row->created_at,
        ];
    }

    public static function uploadedFile(?array $f): ?array
    {
        if (! $f || ! is_array($f)) {
            return null;
        }

        return [
            'id' => (string) ($f['id'] ?? ''),
            'name' => (string) ($f['name'] ?? ''),
            'size' => (int) ($f['size'] ?? 0),
            'type' => (string) ($f['type'] ?? ''),
            'url' => (string) ($f['url'] ?? ''),
            'uploadedAt' => (string) ($f['uploadedAt'] ?? ''),
        ];
    }

    /**
     * Full order shape — identical to formatOrder in the original API,
     * including joined logs and ticketMessages.
     */
    public static function order(Order $o): array
    {
        return [
            'id' => $o->id,
            'orderNumber' => $o->order_number,
            'serviceId' => $o->service_id,
            'serviceTitle' => $o->service_title,
            'customerId' => $o->customer_id,
            'customerPhone' => $o->customer_phone,
            'customerName' => $o->customer_name,
            'status' => $o->status,
            'urgency' => $o->urgency,
            'deliveryMethod' => $o->delivery_method,
            'deliveryAddress' => $o->delivery_address,
            'deliveryPostalCode' => $o->delivery_postal_code,
            'formData' => $o->formData(),
            'customerFiles' => array_values(array_filter(array_map(
                [self::class, 'uploadedFile'], $o->customerFiles()
            ))),
            'resultFiles' => array_values(array_filter(array_map(
                [self::class, 'uploadedFile'], $o->resultFiles()
            ))),
            'totalPrice' => (int) $o->total_price,
            'discountAmount' => (int) $o->discount_amount,
            'paidAmount' => (int) $o->paid_amount,
            'refundAmount' => (int) ($o->refund_amount ?? 0),
            'refundedAt' => $o->refunded_at,
            'paymentMethod' => $o->payment_method,
            'paymentTrackingCode' => $o->payment_tracking_code,
            'assignedOperatorId' => $o->assigned_operator_id,
            'assignedOperatorName' => $o->assigned_operator_name,
            'internalNote' => $o->internal_note,
            'customerNote' => $o->customer_note,
            'contactMethods' => $o->contactMethods(),
            'rating' => $o->rating,
            'reviewComment' => $o->review_comment,
            'createdAt' => $o->created_at,
            'updatedAt' => $o->updated_at,
            // ── Smart queue / capacity fields ─────────────────────────
            // Exposed so the customer-facing tracker + home page can show
            // "you are #N in queue", "estimated start HH:MM", etc.
            'queueType' => (string) ($o->queue_type ?? 'normal'),
            'queuePosition' => $o->queue_position !== null ? (int) $o->queue_position : null,
            'estimatedWaitMinutes' => $o->estimated_wait_minutes !== null ? (int) $o->estimated_wait_minutes : null,
            'estimatedDurationMinutes' => $o->estimated_duration_minutes !== null ? (int) $o->estimated_duration_minutes : null,
            'estimatedStartAt' => $o->estimated_start_at,
            'estimatedEndAt' => $o->estimated_end_at,
            'noResponseDeadline' => $o->no_response_deadline,
            'trackToken' => $o->track_token,
            'reservedSlotId' => $o->reserved_slot_id,
            'logs' => $o->logs()->orderBy('timestamp')->get()->map([self::class, 'orderLog'])->values()->all(),
            'ticketMessages' => $o->ticketMessages()->orderBy('timestamp')->get()->map([self::class, 'ticketMessage'])->values()->all(),
        ];
    }
}
