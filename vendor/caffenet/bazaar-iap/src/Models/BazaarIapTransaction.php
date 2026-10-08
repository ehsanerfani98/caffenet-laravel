<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One in-flight (or finished) in-app purchase / login visit.
 *
 * @property string $id
 * @property string $visit_id
 * @property string|null $package_name
 * @property string|null $sku
 * @property string|null $purchase_token
 * @property string $status
 * @property string|null $purpose
 * @property string|null $reference
 * @property int $amount
 * @property string|null $message
 * @property array|null $meta
 */
class BazaarIapTransaction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_LOGIN = 'login';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'meta' => 'array',
        'consumed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Table name is configurable so multiple apps / tenants can isolate.
        $this->setTable((string) config('bazaar-iap.table', 'bazaar_iap_transactions'));
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = 'bzi-'.Str::uuid();
            }
            if (empty($model->visit_id)) {
                $model->visit_id = (string) Str::uuid();
            }
        });
    }

    public static function findByVisit(string $visitId): ?self
    {
        return static::query()->where('visit_id', $visitId)->first();
    }

    public function markConsumed(?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_CONSUMED,
            'message' => $message,
            'consumed_at' => now(),
        ])->save();
    }

    public function markFailed(?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'message' => $message,
        ])->save();
    }
}
