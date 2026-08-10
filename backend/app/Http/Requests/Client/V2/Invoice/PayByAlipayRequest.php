<?php

namespace App\Http\Requests\Client\V2\Invoice;

use App\Constants\PaymentGatewayCode;
use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use Illuminate\Validation\Rule;

class PayByAlipayRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'payment_session_token' => ['required', 'string', 'min:20', 'max:120'],
            'gateway' => ['nullable', 'string', Rule::in(PaymentGatewayCode::thirdPartyGateways())],
            'payment_type' => ['nullable', 'string', Rule::in(['alipay', 'wxpay'])],
        ];
    }
}
