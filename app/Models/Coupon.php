<?php

namespace App\Models;

class Coupon extends CaffenetModel
{
    protected const ID_PREFIX = 'cpn';

    protected $table = 'coupons';

    protected $fillable = [
        'id', 'code', 'percent', 'max_amount', 'min_order_amount', 'expire_date',
        'usage_count', 'max_usage', 'is_active',
    ];

    protected $casts = [
        'percent' => 'integer',
        'max_amount' => 'integer',
        'min_order_amount' => 'integer',
        'usage_count' => 'integer',
        'max_usage' => 'integer',
        'is_active' => 'boolean',
    ];
}
