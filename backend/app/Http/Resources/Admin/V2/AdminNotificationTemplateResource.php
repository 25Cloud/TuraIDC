<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNotificationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = is_array($this->resource) ? $this->resource : [];

        return [
            'channel' => (string) ($template['channel'] ?? ''),
            'code' => (string) ($template['code'] ?? ''),
            'name' => (string) ($template['name'] ?? ''),
            'description' => (string) ($template['description'] ?? ''),
            'audience' => (string) ($template['audience'] ?? 'user'),
            'subject' => $template['subject'] ?? null,
            'content' => (string) ($template['content'] ?? ''),
            'provider_template_id' => (string) ($template['provider_template_id'] ?? ''),
            'is_enabled' => (bool) ($template['is_enabled'] ?? true),
            'variables' => array_values((array) ($template['variables'] ?? [])),
            'provider_variables' => array_values((array) ($template['provider_variables'] ?? [])),
            'setting_keys' => (array) ($template['setting_keys'] ?? []),
        ];
    }
}
