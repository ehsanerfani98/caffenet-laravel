<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends CaffenetModel
{
    protected const ID_PREFIX = 'cat';

    protected $table = 'categories';

    protected $fillable = ['id', 'title', 'icon_name', 'description'];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
