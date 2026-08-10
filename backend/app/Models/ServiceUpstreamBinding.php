<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceUpstreamBinding extends Model
{
    protected $fillable = [
        'service_id',
        'product_upstream_binding_id',
        'supplier_plugin_binding_id',
        'plugin_id',
        'provider_key',
        'upstream_service_id',
        'upstream_account_id',
        'runtime_snapshot_json',
        'connection_snapshot_json',
        'status_snapshot',
        'last_synced_at',
        'last_sync_error',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'runtime_snapshot_json' => 'array',
            'connection_snapshot_json' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function productUpstreamBinding(): BelongsTo
    {
        return $this->belongsTo(ProductUpstreamBinding::class, 'product_upstream_binding_id');
    }

    public function supplierPluginBinding(): BelongsTo
    {
        return $this->belongsTo(SupplierPluginBinding::class, 'supplier_plugin_binding_id');
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }

    public function runtimeSnapshot(): HasOne
    {
        return $this->hasOne(ServiceRuntimeSnapshot::class, 'service_upstream_binding_id');
    }

    public function connectionSnapshots(): HasMany
    {
        return $this->hasMany(ServiceConnectionSnapshot::class, 'service_upstream_binding_id');
    }

    public function provisionAttempts(): HasMany
    {
        return $this->hasMany(ServiceProvisionAttempt::class, 'service_upstream_binding_id');
    }
}
