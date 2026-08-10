<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class RefundUserInvoiceActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'refund_method' => ['required', 'string', 'in:balance,original'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'remark' => ['required', 'string', 'min:2', 'max:200'],
            'scope' => ['nullable', 'array'],
            'scope.*' => ['string', 'in:order,payment'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'refund_method',
            'amount',
            'remark',
            'scope',
        ]);
    }
}
