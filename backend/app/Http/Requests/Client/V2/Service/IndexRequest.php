<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'status_scope' => ['nullable', 'string', Rule::in(['active_pending'])],
            'quick_filter' => ['nullable', 'string', Rule::in(['expiring_7d', 'auto_renew_enabled', 'auto_renew_disabled', 'auto_renew_7d'])],
            'catalog_type' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
