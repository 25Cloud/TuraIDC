<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\SpecCatalog;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListInstanceSpecCatalogRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:120'],
            'binding_status' => ['nullable', 'string', 'in:bound,unbound'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
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
