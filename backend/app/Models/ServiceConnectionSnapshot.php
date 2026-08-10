<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConnectionSnapshot extends Model
{
    protected $fillable = [
        'service_id',
        'service_upstream_binding_id',
        'plugin_id',
        'provider_key',
        'connection_type',
        'hostname',
        'ip_address',
        'port',
        'connection_json',
        'secret_json',
        'has_secret_json',
        'checked_at',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'connection_json' => 'array',
            'has_secret_json' => 'array',
            'checked_at' => 'datetime',
            'port' => 'integer',
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
