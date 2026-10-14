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

    /** Set when management refunds the order amount to the customer's wallet. */
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT, self::STATUS_SUBMITTED, self::STATUS_PROCESSING,
        self::STATUS_READY, self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    public const STATUS_TITLES = [
        'pending_payment' => 'در انتظار پرداخت',
        'submitted' => 'ثبت‌شده',
        'processing' => 'در حال انجام',
        'ready' => 'آماده تحویل / دانلود',
        'delivered' => 'تحویل داده شده',
        'failed' => 'ناموفق',
        'cancelled' => 'لغو شده',
        'refunded' => 'عودت شده',
    ];

    /** Never shown in the operator queue. */
    public const NON_QUEUE_STATUSES = [self::STATUS_PENDING_PAYMENT, self::STATUS_FAILED, self::STATUS_REFUNDED];

    /** Orders that ARE in the active queue (waiting or being worked on). */
    public const QUEUE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_PROCESSING];

    /**
     * Capacity status tiers shown as the global indicator.
     *  green  — normal capacity, ETA < 15 min
     *  yellow — queue filling up, ETA 15-30 min
     *  orange — busy, ETA 30-60 min
     *  red    — today's capacity exhausted, first slot tomorrow
     */
    public const CAPACITY_GREEN = 'green';
    public const CAPACITY_YELLOW = 'yellow';
    public const CAPACITY_ORANGE = 'orange';
    public const CAPACITY_RED = 'red';

    public const CAPACITY_TIERS = [
        self::CAPACITY_GREEN, self::CAPACITY_YELLOW, self::CAPACITY_ORANGE, self::CAPACITY_RED,
    ];

    public const CAPACITY_TITLES = [
        'green' => 'ظرفیت عادی',
        'yellow' => 'صف در حال افزایش',
        'orange' => 'صف شلوغ',
        'red' => 'پذیرش امروز تکمیل شده',
    ];

    /**
     * Contact channels the customer can prefer (multi-select). Keys are stored
     * in orders.contact_methods_json; titles are the operator-facing labels.
     */
    public const CONTACT_METHODS = [
        'app_chat' => 'چت داخلی برنامه',
        'phone' => 'تماس تلفنی',
        'sms' => 'پیامک',
        'telegram' => 'تلگرام',
        'whatsapp' => 'واتساپ',
        'rubika' => 'روبیکا',
        'bale' => 'بله',
        'eitaa' => 'ایتا',
    ];

    protected const ID_PREFIX = 'ord';

    protected $table = 'orders';

    protected $fillable = [
        'id', 'order_number', 'service_id', 'service_title', 'customer_id', 'customer_phone',
        'customer_name', 'status', 'urgency', 'delivery_method', 'delivery_address',
        'delivery_postal_code', 'form_data_json', 'customer_files_json', 'result_files_json',
        'total_price', 'discount_amount', 'paid_amount', 'payment_method', 'payment_tracking_code',
        'refund_amount', 'refunded_at',
        'assigned_operator_id', 'assigned_operator_name', 'internal_note', 'customer_note',
        'contact_methods_json', 'coupon_id', 'rating', 'review_comment', 'created_at', 'updated_at',
        // Smart queue / capacity fields:
        'queue_type', 'priority_level',
        'estimated_wait_minutes', 'estimated_duration_minutes',
        'estimated_start_at', 'estimated_end_at',
        'track_token', 'queue_position',
        'no_response_deadline', 'reserved_slot_id',
    ];

    protected $casts = [
        'total_price' => 'integer',
        'discount_amount' => 'integer',
        'paid_amount' => 'integer',
        'refund_amount' => 'integer',
        'rating' => 'integer',
        'priority_level' => 'integer',
        'estimated_wait_minutes' => 'integer',
        'estimated_duration_minutes' => 'integer',
        'queue_position' => 'integer',
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

    public function queueEntry(): HasMany
    {
        return $this->hasMany(QueueEntry::class, 'order_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'order_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id');
    }

    public function reservedSlot(): BelongsTo
    {
        return $this->belongsTo(CapacitySlot::class, 'reserved_slot_id');
    }

    public function formData(): array
    {
        $decoded = json_decode((string) $this->form_data_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Preferred contact channels as a clean, de-duplicated list of keys. */
    public function contactMethods(): array
    {
        $decoded = json_decode((string) $this->contact_methods_json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $allowed = array_keys(self::CONTACT_METHODS);
        $out = [];
        foreach ($decoded as $method) {
            if (is_string($method) && in_array($method, $allowed, true) && ! in_array($method, $out, true)) {
                $out[] = $method;
            }
        }

        return $out;
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
