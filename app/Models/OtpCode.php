<?php

namespace App\Models;

class OtpCode extends CaffenetModel
{
    protected const ID_PREFIX = 'otp';

    protected $table = 'otp_codes';

    protected $fillable = [
        'id', 'phone', 'code_hash', 'attempts', 'consumed', 'request_ip', 'expires_at', 'created_at',
    ];

    protected $casts = ['attempts' => 'integer', 'consumed' => 'boolean'];
}
