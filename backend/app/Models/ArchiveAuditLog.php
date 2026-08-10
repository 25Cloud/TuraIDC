<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'table_name',
        'mode',
        'row_count',
        'file_path',
        'file_size',
        'checksum_sha256',
        'status',
        'error_message',
        'started_at',
        'finished_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
