<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminIntegrationPluginSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => $item['id'] ?? null,
            'domain' => (string) ($item['domain'] ?? ''),
            'slug' => (string) ($item['slug'] ?? ''),
            'key' => (string) ($item['key'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'version' => (string) ($item['version'] ?? ''),
            'provider_class' => $item['provider_class'] ?? null,
            'capabilities' => is_array($item['capabilities'] ?? null) ? $item['capabilities'] : [],
            'is_installed' => (bool) ($item['is_installed'] ?? false),
            'is_enabled' => (bool) ($item['is_enabled'] ?? false),
            'can_enable' => (bool) ($item['can_enable'] ?? false),
            'enable_disabled_reason' => $item['enable_disabled_reason'] ?? null,
            'status' => (int) ($item['status'] ?? 0),
            'installed_at' => $item['installed_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'binding_counts' => is_array($item['binding_counts'] ?? null) ? $item['binding_counts'] : [],
            'business_reference_count' => (int) ($item['business_reference_count'] ?? 0),
            'latest_runtime_log' => $this->runtimeLog($item['latest_runtime_log'] ?? null),
            'manifest_missing' => (bool) ($item['manifest_missing'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function runtimeLog(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'id' => isset($value['id']) ? (int) $value['id'] : null,
            'trace_id' => (string) ($value['trace_id'] ?? ''),
            'action' => (string) ($value['action'] ?? ''),
            'status' => (string) ($value['status'] ?? ''),
            'created_at' => $value['created_at'] ?? null,
        ];
    }
}
