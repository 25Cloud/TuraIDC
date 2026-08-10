<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Invoice;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowInvoiceRequest extends AdminFormRequest
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
