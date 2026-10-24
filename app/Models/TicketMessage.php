<?php

namespace App\Models;

class TicketMessage extends CaffenetModel
{
    protected const ID_PREFIX = 'tkt';

    protected $table = 'ticket_messages';

    protected $fillable = [
        'id', 'order_id', 'sender_role', 'sender_name', 'message', 'is_operator_response',
        'staff_read_at', 'customer_read_at', 'timestamp',
    ];

    protected $casts = ['is_operator_response' => 'boolean'];
}
