<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Supplier extends Model
{
    protected $hidden = [];

    protected $fillable = [
        'name',
        'code',
        'contact_name',
        'contact_phone',
        'contact_email',
        'website',
        'status',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', 1);
    }

    public function pluginBindings(): HasMany
    {
        return $this->hasMany(SupplierPluginBinding::class, 'supplier_id');
    }

    /**
     * 上游余额台账（每供应商至多一行），供列表接口预加载以避免 N+1。
     */
    public function balanceRecord(): HasOne
    {
        return $this->hasOne(SupplierBalance::class, 'supplier_id');
    }
}
