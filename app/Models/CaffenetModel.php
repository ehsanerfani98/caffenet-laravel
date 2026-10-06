<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base model for the string-keyed Caffenet tables. Identifiers keep the
 * original prefixed-UUID format (usr-…, ord-…, …) for data compatibility.
 */
abstract class CaffenetModel extends Model
{
    protected const ID_PREFIX = '';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = static::ID_PREFIX.'-'.Str::uuid();
            }
        });
    }
}
