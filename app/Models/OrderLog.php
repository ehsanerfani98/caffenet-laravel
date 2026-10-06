<?php

namespace App\Models;

class OrderLog extends CaffenetModel
{
    protected const ID_PREFIX = 'log';

    protected $table = 'order_logs';

    protected $fillable = ['id', 'order_id', 'action', 'performed_by', 'note', 'timestamp'];
}
