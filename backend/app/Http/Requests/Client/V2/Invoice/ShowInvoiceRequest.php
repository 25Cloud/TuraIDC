<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class ShowInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice' => ['required', 'integer', 'min:1'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'invoice' => $this->route('invoice'),
        ]);
    }
}
