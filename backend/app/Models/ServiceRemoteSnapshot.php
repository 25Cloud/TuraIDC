<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRemoteSnapshot extends Model
{
    protected $table = 'service_remote_snapshots';

    protected $fillable = [
        'service_instance_id',
        'snapshot_type',
        'snapshot_key',
        'snapshot_payload_json',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'service_instance_id' => 'integer',
            'snapshot_payload_json' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class, 'service_instance_id');
    }
}
