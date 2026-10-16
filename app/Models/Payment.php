<?php

namespace App\Models;

class Payment extends CaffenetModel
{
    protected const ID_PREFIX = 'pay';

    protected $table = 'payments';

    protected $fillable = [
        'id', 'order_id', 'user_id', 'purpose', 'amount', 'authority', 'ref_id',
        'card_pan', 'gateway', 'status', 'created_at', 'verified_at',
        'contract_token',
    ];

    protected $casts = ['amount' => 'integer'];
}
