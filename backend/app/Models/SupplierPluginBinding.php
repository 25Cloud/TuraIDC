<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPluginBinding extends Model
{
    protected $fillable = [
        'supplier_id',
        'plugin_id',
        'provider_key',
        'environment',
        'status',
        'priority',
        'base_url',
        'account_name',
        'config_json',
        'secret_json',
        'has_secret_json',
        'last_checked_at',
        'last_check_status',
        'last_check_error',
        'created_by',
        'updated_by',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'has_secret_json' => 'array',
            'last_checked_at' => 'datetime',
            'status' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }

    public function productBindings(): HasMany
    {
        return $this->hasMany(ProductUpstreamBinding::class, 'supplier_plugin_binding_id');
    }
}
