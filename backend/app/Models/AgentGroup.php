<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentGroup extends Model
{
    protected $fillable = [
        'name', 'code', 'status', 'sort_order', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(AgentGroupDiscount::class);
    }
}
