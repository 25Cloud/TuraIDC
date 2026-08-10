<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductConfigOption extends Model
{
    protected $table = 'product_config_options';

    protected $fillable = [
        'product_id',
        'option_key',
        'option_label',
        'option_type',
        'is_required',
        'default_value',
        'option_schema_json',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'is_required' => 'boolean',
            'option_schema_json' => 'array',
            'sort_order' => 'integer',
            'status' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
