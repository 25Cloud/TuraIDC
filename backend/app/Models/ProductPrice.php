<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $fillable = [
        'product_id',
        'billing_cycle',
        'price',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'price' => 'decimal:2',
            'is_enabled' => 'integer',
        ];
    }
}
