<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductGroup;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class ListProductGroupChildrenRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            'level' => ['required', 'integer', Rule::in([1, 2])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1])],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'group' => $this->route('group'),
        ]);
    }
}
