<?php

namespace App\Models;

class SliderImage extends CaffenetModel
{
    protected const ID_PREFIX = 'sld';

    protected $table = 'slider_images';

    protected $fillable = [
        'id', 'image_url', 'title', 'subtitle', 'cta_label', 'cta_target',
        'is_active', 'sort_order', 'created_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
