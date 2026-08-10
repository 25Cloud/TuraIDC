<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRuntimeSnapshot extends Model
{
    protected $fillable = [
        'service_id',
        'service_upstream_binding_id',
        'plugin_id',
        'provider_key',
        'status_key',
        'status_text',
        'resource_json',
        'metrics_json',
        'snapshot_json',
        'synced_at',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'resource_json' => 'array',
            'metrics_json' => 'array',
            'snapshot_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function upstreamBinding(): BelongsTo
    {
        return $this->belongsTo(ServiceUpstreamBinding::class, 'service_upstream_binding_id');
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }
}
