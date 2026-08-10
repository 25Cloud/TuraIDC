<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationPluginRuntimeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trace_id',
        'domain',
        'plugin_id',
        'plugin_key',
        'slug',
        'action',
        'binding_id',
        'bindable_type',
        'bindable_id',
        'actor_type',
        'actor_id',
        'status',
        'duration_ms',
        'error_code',
        'error_message',
        'request_meta_json',
        'response_meta_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_meta_json' => 'array',
            'response_meta_json' => 'array',
            'created_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }
}
