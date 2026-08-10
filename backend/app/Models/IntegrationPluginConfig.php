<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationPluginConfig extends Model
{
    protected $fillable = [
        'plugin_id',
        'config_json',
        'secret_json',
        'has_secret_json',
        'updated_by',
    ];

    protected $casts = [
        'config_json' => 'array',
        'has_secret_json' => 'array',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(IntegrationPlugin::class, 'plugin_id');
    }
}
