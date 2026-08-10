<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondProductGroup extends Model
{
    protected $table = 'second_product_groups';

    protected $fillable = [
        'first_product_group_id',
        'name',
        'slug',
        'description',
        'banner_image',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'first_product_group_id' => 'integer',
            'sort_order' => 'integer',
            'is_visible' => 'integer',
        ];
    }

    public function firstProductGroup(): BelongsTo
    {
        return $this->belongsTo(FirstProductGroup::class, 'first_product_group_id');
    }

    public function thirdProductGroups(): HasMany
    {
        return $this->hasMany(ThirdProductGroup::class, 'second_product_group_id');
    }
}
