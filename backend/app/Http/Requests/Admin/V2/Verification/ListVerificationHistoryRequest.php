<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Verification;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListVerificationHistoryRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function pageSize(): int
    {
        return $this->perPage(20, 100);
    }
}
