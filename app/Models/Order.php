<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order — the payment-centric lifecycle core of the system.
 *
 * Created as pending_payment with paid_amount = 0; moves to submitted only
 * after a verified payment (online zarinpal or internal wallet), to failed on
 * gateway/verify errors or 30-minute abandonment, and cancelled on request.
 */
class Order extends CaffenetModel
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT, self::STATUS_SUBMITTED, self::STATUS_PROCESSING,
        self::STATUS_READY, self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    public const STATUS_TITLES = [
        'pending_payment' => 'در انتظار پرداخت',
        'submitted' => 'ثبت‌شده',
        'processing' => 'در حال انجام',
        'ready' => 'آماده تحویل / دانلود',
        'delivered' => 'تحویل داده شده',
        'failed' => 'ناموفق',
        'cancelled' => 'لغو شده',
    ];

    /** Never shown in the operator queue. */
    public const NON_QUEUE_STATUSES = [self::STATUS_PENDING_PAYMENT, self::STATUS_FAILED];

    protected const ID_PREFIX = 'ord';

    protected $table = 'orders';

    protected $fillable = [
        'id', 'order_number', 'service_id', 'service_title', 'customer_id', 'customer_phone',
        'customer_name', 'status', 'urgency', 'delivery_method', 'delivery_address',
        'delivery_postal_code', 'form_data_json', 'customer_files_json', 'result_files_json',
        'total_price', 'discount_amount', 'paid_amount', 'payment_method', 'payment_tracking_code',
        'assigned_operator_id', 'assigned_operator_name', 'internal_note', 'customer_note',
        'coupon_id', 'rating', 'review_comment', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'total_price' => 'integer',
        'discount_amount' => 'integer',
        'paid_amount' => 'integer',
        'rating' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class, 'order_id');
    }

    public function ticketMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function formData(): array
    {
        $decoded = json_decode((string) $this->form_data_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function customerFiles(): array
    {
        $decoded = json_decode((string) $this->customer_files_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function resultFiles(): array
    {
        $decoded = json_decode((string) $this->result_files_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
