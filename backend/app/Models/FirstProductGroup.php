<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FirstProductGroup extends Model
{
    protected $table = 'first_product_groups';

    protected $fillable = [
        'code',
        'product_type',
        'name',
        'slug',
        'description',
        'icon',
        'banner_image',
        'sort_order',
        'is_visible',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'integer',
            'is_system' => 'integer',
        ];
    }

    public function secondProductGroups(): HasMany
    {
        return $this->hasMany(SecondProductGroup::class, 'first_product_group_id');
    }
}
