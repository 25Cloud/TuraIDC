<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\NormalizesTraceId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOperationLog extends Model
{
    use NormalizesTraceId;

    protected $table = 'service_operation_logs';

    protected $fillable = [
        'service_instance_id',
        'operation_type',
        'request_payload_json',
        'response_payload_json',
        'result_status',
        'provider_request_id',
        'error_message',
        'operator_type',
        'operator_id',
        'trace_id',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'service_instance_id' => 'integer',
            'operator_id' => 'integer',
            'request_payload_json' => 'array',
            'response_payload_json' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }
}
