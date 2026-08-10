<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Service;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class BatchUpdateServiceCustomHostnameRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.service_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.hostname' => ['nullable', 'string', 'max:200'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array{items: list<array{service_id: int, hostname: string}>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'items' => collect((array) ($validated['items'] ?? []))
                ->map(fn (array $item): array => [
                    'service_id' => (int) ($item['service_id'] ?? 0),
                    'hostname' => trim((string) ($item['hostname'] ?? '')),
                ])
                ->values()
                ->all(),
        ];
    }
}
