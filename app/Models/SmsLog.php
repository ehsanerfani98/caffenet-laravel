<?php

namespace App\Models;

class SmsLog extends CaffenetModel
{
    protected const ID_PREFIX = 'sms';

    protected $table = 'sms_logs';

    protected $fillable = [
        'id', 'phone', 'text', 'type', 'status', 'provider_message_id', 'error', 'timestamp',
    ];
}
