<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_product_group_id' => ['nullable', 'integer', 'min:1'],
            'effective_product_group_ids' => ['nullable', 'array', 'min:1'],
            'effective_product_group_ids.*' => ['integer', 'min:1'],
            'second_product_group_id' => ['nullable', 'integer', 'min:1'],
            'second_product_group_ids' => ['nullable', 'array', 'min:1'],
            'second_product_group_ids.*' => ['integer', 'min:1'],
            'third_product_group_id' => ['nullable', 'integer', 'min:1'],
            'third_product_group_ids' => ['nullable', 'array', 'min:1'],
            'third_product_group_ids.*' => ['integer', 'min:1'],
        ];
    }
}
