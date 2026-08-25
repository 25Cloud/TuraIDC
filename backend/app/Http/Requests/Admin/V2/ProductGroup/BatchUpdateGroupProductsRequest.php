<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductGroup;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class BatchUpdateGroupProductsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            'effective_product_group_level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'console_template' => ['nullable', 'string', Rule::in(['compute', 'port_mapping'])],
            'product_discount_group_id' => ['nullable', 'integer', 'min:0'],
            'cpu_model' => ['nullable', 'string', 'max:120'],
            'cpu_turbo' => ['nullable', 'string', 'max:40'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'group' => $this->route('group'),
        ]);
    }
}
