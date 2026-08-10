<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'group' => (string) ($item['group'] ?? ''),
            'key' => (string) ($item['key'] ?? ''),
            'value' => $item['value'] ?? null,
            'sensitive' => (bool) ($item['is_secret'] ?? false),
            'configured' => (bool) ($item['has_value'] ?? false),
            'display_value' => $item['masked_value'] ?? '',
        ];
    }
}
