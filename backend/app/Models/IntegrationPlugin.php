<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationPlugin extends Model
{
    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = [
        'domain',
        'slug',
        'plugin_key',
        'name',
        'version',
        'provider_class',
        'entry_class',
        'capabilities_json',
        'config_schema_json',
        'status',
        'installed_at',
        'enabled_at',
        'disabled_at',
        'installed_by',
        'enabled_by',
        'source_hash',
        // 缺了这项会让 PluginInstaller 的 updateOrCreate 批量赋值把它静默丢弃，
        // manifest_hash 恒为 NULL，PluginScanner::assertManifestHash() 提前返回，
        // 插件清单篡改检测永远不会触发。
        'manifest_hash',
    ];

    protected $casts = [
        'capabilities_json' => 'array',
        'config_schema_json' => 'array',
        'installed_at' => 'datetime',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function config(): HasOne
    {
        return $this->hasOne(IntegrationPluginConfig::class, 'plugin_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(IntegrationPluginBinding::class, 'plugin_id');
    }

    public function supplierBindings(): HasMany
    {
        return $this->hasMany(SupplierPluginBinding::class, 'plugin_id');
    }

    public function isEnabled(): bool
    {
        return (int) $this->status === self::STATUS_ENABLED;
    }
}
