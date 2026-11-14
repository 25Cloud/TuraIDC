<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ZjmfHostSyncEvent extends Model
{
    protected $table = 'zjmf_host_sync_events';

    protected $fillable = [
        'service_id', 'event_key', 'signature_version', 'nonce_hash', 'payload_hash',
        'status', 'response_json', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
