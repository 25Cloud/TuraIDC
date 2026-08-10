<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductOperationPayloadResource extends JsonResource
{
    private const MAX_LIST_ITEMS = 200;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->sanitizeArray(is_array($this->resource) ? $this->resource : []);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function sanitizeArray(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $value = array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->sanitizeArray($item) : $item, array_slice($value, 0, self::MAX_LIST_ITEMS))
                    : $this->sanitizeArray($value);
            }

            $clean[$key] = $value;
        }

        return $clean;
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
