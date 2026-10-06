<?php

namespace App\Models;

class Setting extends CaffenetModel
{
    protected const ID_PREFIX = 'set';

    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];
}
