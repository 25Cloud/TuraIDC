<?php

declare(strict_types=1);

namespace App\Http\Requests\Open\V2;

use Illuminate\Foundation\Http\FormRequest;

class OpenOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'billing_cycle' => ['required', 'string'],
            'quote_token' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'config' => ['nullable', 'array'],
        ];
    }
}
