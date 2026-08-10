<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Service;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListServicesRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'keyword' => trim((string) $this->input('keyword', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->validated();
    }
}
