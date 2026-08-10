<?php

namespace App\Http\Requests\Client\V2\Referral;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class ApplyWithdrawalRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'in:balance,alipay'],
            'account_name' => ['nullable', 'string', 'max:80'],
            'account_no' => ['nullable', 'regex:/^1[3-9]\d{9}$/'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
