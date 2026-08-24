<?php

declare(strict_types=1);

namespace App\Http\Requests\Open\V2;

use Illuminate\Foundation\Http\FormRequest;

class OpenQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_cycle' => ['required', 'string'],
            'config' => ['nullable', 'array'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
