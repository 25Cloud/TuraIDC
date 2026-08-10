<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPricingPlan extends Model
{
    protected $table = 'product_pricing_plans';

    protected $fillable = [
        'product_id',
        'billing_cycle',
        'currency',
        'sale_price',
        'renewal_price',
        'setup_fee',
        'stock_mode',
        'stock_value',
        'is_default',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'sale_price' => 'decimal:2',
            'renewal_price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'stock_value' => 'integer',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'status' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
