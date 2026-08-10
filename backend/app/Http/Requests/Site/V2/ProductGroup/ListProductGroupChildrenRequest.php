<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2\ProductGroup;

use Illuminate\Foundation\Http\FormRequest;

class ListProductGroupChildrenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'group' => $this->route('group'),
        ]);
    }
}
