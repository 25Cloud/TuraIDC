<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSupplierProductConfigTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'supplier_id' => (int) ($item['supplier_id'] ?? 0),
            'supplier_name' => (string) ($item['supplier_name'] ?? ''),
            'upstream_product_id' => (int) ($item['upstream_product_id'] ?? 0),
            'product' => $this->sanitizeArray($item['product'] ?? []),
            'config_options' => $this->sanitizeArray($item['config_options'] ?? []),
            'auto_filled_fields' => $this->sanitizeArray($item['auto_filled_fields'] ?? []),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function sanitizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($item) ? $this->sanitizeArray($item) : $item;
        }

        return $sanitized;
    }

    private function isSensitiveKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        foreach (['password', 'secret', 'api_key', 'token', 'raw_response', 'third_party_response'] as $needle) {
            if (str_contains(strtolower($key), $needle)) {
                return true;
            }
        }

        return false;
    }
}
