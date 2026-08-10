<?php

namespace App\Http\Requests\Client\V2\Recharge;

use App\Constants\PaymentGatewayCode;
use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:50000'],
            'gateway' => ['nullable', 'string', Rule::in(PaymentGatewayCode::thirdPartyGateways())],
            'payment_type' => ['nullable', 'string', Rule::in(['alipay', 'wxpay'])],
        ];
    }
}
