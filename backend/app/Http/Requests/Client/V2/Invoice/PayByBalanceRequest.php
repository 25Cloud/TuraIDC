<?php

namespace App\Http\Requests\Client\V2\Invoice;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class PayByBalanceRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'payment_session_token' => ['required', 'string', 'min:20', 'max:120'],
        ];
    }
}
