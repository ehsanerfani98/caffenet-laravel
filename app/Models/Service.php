<?php

namespace App\Models;

class Service extends CaffenetModel
{
    protected const ID_PREFIX = 'srv';

    protected $table = 'services';

    protected $fillable = [
        'id', 'category_id', 'title', 'description', 'base_price', 'estimated_time',
        'icon', 'is_active', 'fields_json', 'pricing_formula',
    ];

    protected $casts = [
        'base_price' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Dynamic form-builder fields (text/number/select/…) as an array. */
    public function fields(): array
    {
        $raw = $this->fields_json;
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
