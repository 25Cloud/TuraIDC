<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Common;

use Illuminate\Foundation\Http\FormRequest;

abstract class ClientFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(int $maxPageSize = 50): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:'.$maxPageSize],
        ];
    }

    public function perPage(int $default = 15, int $max = 50): int
    {
        $validated = $this->validated();
        $pageSize = (int) ($validated['page_size'] ?? $default);

        return max(1, min($pageSize, $max));
    }
}
