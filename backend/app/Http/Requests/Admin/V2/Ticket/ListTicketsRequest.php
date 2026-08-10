<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListTicketsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable'],
            'priority' => ['sometimes', 'nullable', 'integer', 'in:1,2,3,4'],
            'department' => ['sometimes', 'nullable', 'string', 'in:sales,support,billing,abuse'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filters = [];

        foreach (['keyword', 'status', 'priority', 'department'] as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $value = $validated[$key];
            if ($value === null || $value === '') {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    public function pageSize(): int
    {
        return $this->perPage(20, 100);
    }
}
