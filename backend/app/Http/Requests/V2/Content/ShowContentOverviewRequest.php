<?php

declare(strict_types=1);

namespace App\Http\Requests\V2\Content;

use Illuminate\Foundation\Http\FormRequest;

class ShowContentOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
