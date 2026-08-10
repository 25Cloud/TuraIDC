<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;

class AdminIntegrationPluginDetailResource extends AdminIntegrationPluginSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return array_merge(parent::toArray($request), [
            'entry_class' => (string) ($item['entry_class'] ?? ''),
            'config' => is_array($item['config'] ?? null) ? $item['config'] : [],
            'configured_credentials' => is_array($item['has_secret_values'] ?? null) ? $item['has_secret_values'] : [],
            'credential_previews' => is_array($item['secret_previews'] ?? null) ? $item['secret_previews'] : [],
        ]);
    }
}
