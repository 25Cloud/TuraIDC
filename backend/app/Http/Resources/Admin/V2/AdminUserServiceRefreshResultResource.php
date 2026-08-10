<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Http\Resources\Admin\V2\Concerns\StripsSensitiveResourceData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserServiceRefreshResultResource extends JsonResource
{
    use StripsSensitiveResourceData;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = collect(is_array($this->resource) ? $this->resource : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => $this->stripSensitiveKeys($item))
            ->values()
            ->all();

        return [
            'refreshed_count' => count($items),
            'services' => $items,
        ];
    }
}
