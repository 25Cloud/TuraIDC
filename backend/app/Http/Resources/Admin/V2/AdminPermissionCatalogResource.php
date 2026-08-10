<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPermissionCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'key' => (string) ($item['key'] ?? ''),
            'module' => (string) ($item['module'] ?? ''),
            'module_label' => (string) ($item['module_label'] ?? ''),
            'group' => (string) ($item['group'] ?? ''),
            'group_label' => (string) ($item['group_label'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'action' => (string) ($item['action'] ?? ''),
            'action_label' => (string) ($item['action_label'] ?? ''),
            'risk_level' => (string) ($item['risk_level'] ?? 'low'),
            'is_dangerous' => (bool) ($item['is_dangerous'] ?? false),
            'is_all' => (bool) ($item['is_all'] ?? false),
            'sort' => (int) ($item['sort'] ?? 0),
        ];
    }
}
