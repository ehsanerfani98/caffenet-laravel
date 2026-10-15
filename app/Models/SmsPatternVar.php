<?php

namespace App\Models;

class SmsPatternVar extends CaffenetModel
{
    protected const ID_PREFIX = 'smspv';

    protected $table = 'sms_pattern_vars';

    protected $fillable = [
        'id', 'pattern_key', 'var_name', 'data_field', 'sort_order', 'created_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
