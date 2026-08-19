<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ticket;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use Illuminate\Validation\Rule;

class ListTicketsRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([0, 1, 2, 3, '0', '1', '2', '3'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only(['keyword', 'status']);
    }

    public function perPage(int $default = 15): int
    {
        return max(1, min((int) $this->integer('page_size', $default), 50));
    }
}
