<?php

namespace App\Models;

/**
 * BazaarPayContract — a Direct Pay (دایرکت‌پی) contract between the merchant
 * and a user's Cafe Bazaar wallet / bank account.
 *
 * One row per BazaarPay `contract_token`. The cached `state` mirrors the
 * gateway (`new | active | declined | cancelled | expired`) and is refreshed
 * from /direct-pay/contract/trace whenever it matters; `amount_limit` and
 * `period` define how much the merchant may auto-charge per period.
 */
class BazaarPayContract extends CaffenetModel
{
    protected const ID_PREFIX = 'bpc';

    protected $table = 'bazaarpay_contracts';

    public const TYPE_WALLET = 'wallet';

    public const TYPE_DIRECT_DEBIT = 'direct_debit';

    public const STATE_NEW = 'new';

    public const STATE_ACTIVE = 'active';

    public const STATE_DECLINED = 'declined';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_EXPIRED = 'expired';

    public const STATE_TITLES = [
        self::STATE_NEW => 'در انتظار تایید کاربر',
        self::STATE_ACTIVE => 'فعال',
        self::STATE_DECLINED => 'رد شده توسط کاربر',
        self::STATE_CANCELLED => 'لغو شده',
        self::STATE_EXPIRED => 'منقضی شده',
    ];

    protected $fillable = [
        'id', 'user_id', 'contract_token', 'type', 'period', 'amount_limit',
        'state', 'expiration_time', 'limit_expiration_time',
        'limit_remaining_amount', 'created_at', 'updated_at', 'activated_at',
    ];

    protected $casts = ['amount_limit' => 'integer', 'limit_remaining_amount' => 'integer'];
}
