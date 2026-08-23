<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\SpecCatalog;

use Illuminate\Foundation\Http\FormRequest;

class SaveProductSpecHighlightRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*' => ['nullable', 'string', 'max:40'],
        ];
    }
}
