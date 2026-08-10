<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2;

use Illuminate\Foundation\Http\FormRequest;

class SiteHomeHeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
        ];
    }
}
