<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentGroupDiscount extends Model
{
    protected $fillable = [
        'agent_group_id', 'product_discount_group_id', 'discount_rate',
    ];

    protected function casts(): array
    {
        return [
            'agent_group_id' => 'integer',
            'product_discount_group_id' => 'integer',
            'discount_rate' => 'decimal:2',
        ];
    }

    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }

    public function productDiscountGroup(): BelongsTo
    {
        return $this->belongsTo(ProductDiscountGroup::class);
    }
}
