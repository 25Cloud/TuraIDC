<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\SpecCatalog;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class SaveCpuModelCatalogRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'list' => ['required', 'array'],
            'list.*.id' => ['nullable', 'string', 'max:80'],
            'list.*.value' => ['nullable', 'string', 'max:60'],
            'list.*.name' => ['required', 'string', 'max:80'],
            'list.*.models' => ['nullable', 'array'],
            'list.*.models.*.id' => ['nullable', 'string', 'max:80'],
            'list.*.models.*.value' => ['nullable', 'string', 'max:60'],
            'list.*.models.*.name' => ['required', 'string', 'max:80'],
            'list.*.models.*.base_frequency' => ['nullable', 'string', 'max:40'],
            'list.*.models.*.turbo_frequency' => ['nullable', 'string', 'max:40'],
            'list.*.models.*.bindings' => ['nullable', 'array'],
            'list.*.models.*.bindings.*.product_id' => ['required', 'integer', 'min:1'],
            'list.*.models.*.bindings.*.category_full_name' => ['nullable', 'string', 'max:160'],
            'list.*.models.*.bindings.*.primary_price' => ['nullable', 'array'],
            'list.*.models.*.bindings.*.primary_price.cycle' => ['nullable', 'string', 'max:40'],
            'list.*.models.*.bindings.*.primary_price.amount' => ['nullable', 'string', 'max:40'],
            'list.*.models.*.bindings.*.status' => ['nullable', 'integer', 'in:0,1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return $this->validated('list');
    }
}
