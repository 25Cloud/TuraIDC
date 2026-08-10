<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Service;

use Illuminate\Foundation\Http\FormRequest;

class ShowServiceConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'service' => $this->route('service'),
        ]);
    }
}
