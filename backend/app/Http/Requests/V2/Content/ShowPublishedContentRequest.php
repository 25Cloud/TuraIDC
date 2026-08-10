<?php

declare(strict_types=1);

namespace App\Http\Requests\V2\Content;

use Illuminate\Foundation\Http\FormRequest;

class ShowPublishedContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'article' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'article' => $this->route('article'),
        ]);
    }
}
