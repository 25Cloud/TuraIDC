<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSupplierRemoteProductsResource extends JsonResource
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
            'groups' => $this->sanitizeGroups($item['groups'] ?? []),
            'products' => $this->sanitizeItems($item['products'] ?? []),
            'truncated' => false,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function sanitizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_map(fn (mixed $item): array => $this->sanitizeArray($item), array_values($items));
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    private function sanitizeGroups(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $result = [];
        foreach (array_values($groups) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $result[] = $this->sanitizeArray($group);
        }

        return $result;
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
