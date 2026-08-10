<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUpstreamBinding extends Model
{
    protected $fillable = [
        'product_id',
        'supplier_plugin_binding_id',
        'plugin_id',
        'provider_key',
        'upstream_product_id',
        'upstream_product_snapshot_json',
        'option_schema_json',
        'provision_policy_json',
        'auto_setup',
        'status',
        'last_synced_at',
        'last_sync_error',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'upstream_product_snapshot_json' => 'array',
            'option_schema_json' => 'array',
            'provision_policy_json' => 'array',
            'auto_setup' => 'boolean',
            'status' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function supplierPluginBinding(): BelongsTo
    {
        return $this->belongsTo(SupplierPluginBinding::class, 'supplier_plugin_binding_id');
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }

    public function serviceBindings(): HasMany
    {
        return $this->hasMany(ServiceUpstreamBinding::class, 'product_upstream_binding_id');
    }
}
