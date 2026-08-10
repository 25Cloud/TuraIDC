<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'item_name',
        'item_type',
        'quantity',
        'unit_price',
        'discount_amount',
        'line_amount',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_amount' => 'decimal:2',
            'meta_json' => 'array',
        ];
    }
}
