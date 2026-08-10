<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceLifecycleLog extends Model
{
    use NormalizesTraceId;

    protected $table = 'service_lifecycle_logs';

    protected $fillable = [
        'service_instance_id',
        'action',
        'from_status',
        'to_status',
        'reason',
        'payload_json',
        'operator_type',
        'operator_id',
        'trace_id',
        'happened_at',
    ];

    protected function casts(): array
    {
        return [
            'service_instance_id' => 'integer',
            'from_status' => 'integer',
            'to_status' => 'integer',
            'operator_id' => 'integer',
            'payload_json' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }
}
