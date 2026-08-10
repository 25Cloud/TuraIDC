<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationPluginBinding extends Model
{
    protected $fillable = [
        'domain',
        'plugin_id',
        'binding_type',
        'bindable_type',
        'bindable_id',
        'binding_key',
        'provider_key',
        'priority',
        'status',
        'config_json',
        'secret_json',
        'has_secret_json',
        'runtime_policy_json',
        'created_by',
        'updated_by',
        'backfill_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'has_secret_json' => 'array',
            'runtime_policy_json' => 'array',
            'priority' => 'integer',
            'status' => 'integer',
            'bindable_id' => 'integer',
        ];
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }
}
