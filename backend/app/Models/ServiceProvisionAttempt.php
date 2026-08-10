<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceProvisionAttempt extends Model
{
    protected $fillable = [
        'service_id',
        'service_upstream_binding_id',
        'plugin_id',
        'provider_key',
        'action',
        'attempt_status',
        'trace_id',
        'request_meta_json',
        'response_meta_json',
        'error_code',
        'error_message',
        'attempted_at',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'request_meta_json' => 'array',
            'response_meta_json' => 'array',
            'attempted_at' => 'datetime',
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
