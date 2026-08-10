<?php

namespace App\Http\Requests\Client\V2\Invoice;

use App\Constants\PaymentGatewayCode;
use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use Illuminate\Validation\Rule;

class QueryAlipayStatusRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'payment_no' => ['required', 'string', 'max:50'],
            'poll_token' => ['required', 'string', 'min:20', 'max:120'],
            'gateway' => ['nullable', 'string', Rule::in(PaymentGatewayCode::thirdPartyGateways())],
        ];
    }
}
