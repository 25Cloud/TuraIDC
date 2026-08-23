<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminIntegrationPluginSchemaFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $field = is_array($this->resource) ? $this->resource : [];

        $payload = [
            'key' => (string) ($field['key'] ?? ''),
            'label' => (string) ($field['label'] ?? $field['title'] ?? ''),
            'type' => (string) ($field['type'] ?? 'text'),
            'required' => (bool) ($field['required'] ?? false),
            'sensitive' => (bool) ($field['secret'] ?? false),
            'options' => $field['options'] ?? null,
            'default' => $field['default'] ?? null,
            'placeholder' => $field['placeholder'] ?? null,
            'description' => $field['description'] ?? null,
            'content' => $field['content'] ?? null,
            'theme' => $field['theme'] ?? null,
            'width' => $field['width'] ?? null,
            'disabled' => isset($field['disabled']) ? (bool) $field['disabled'] : null,
            'visible' => isset($field['visible']) ? (bool) $field['visible'] : null,
            'min' => $field['min'] ?? null,
            'max' => $field['max'] ?? null,
            'step' => $field['step'] ?? null,
            'rows' => $field['rows'] ?? null,
            'visible_when' => $field['visible_when'] ?? null,
            // divider 用：该分组是否可折叠、初始是否收起。
            // 高级项大多有合理默认值，收起来能避免管理员误改而配坏。
            'collapsible' => isset($field['collapsible']) ? (bool) $field['collapsible'] : null,
            'collapsed' => isset($field['collapsed']) ? (bool) $field['collapsed'] : null,
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
