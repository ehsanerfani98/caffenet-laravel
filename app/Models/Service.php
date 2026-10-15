<?php

namespace App\Models;

class Service extends CaffenetModel
{
    protected const ID_PREFIX = 'srv';

    protected $table = 'services';

    protected $fillable = [
        'id', 'category_id', 'title', 'description', 'base_price', 'estimated_time',
        'icon', 'icon_color', 'sort_order', 'is_active', 'is_featured', 'featured_sort',
        'fields_json', 'pricing_formula',
    ];

    protected $casts = [
        'base_price' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'featured_sort' => 'integer',
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
