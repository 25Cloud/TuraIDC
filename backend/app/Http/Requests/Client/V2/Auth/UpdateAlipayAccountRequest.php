<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateAlipayAccountRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'real_name' => ['required', 'string', 'max:80'],
            'account' => ['required', 'regex:/^1[3-9]\d{9}$/'],
            'code' => ['required', 'string', 'size:6'],
            // 绑定/改绑提现账户必须登录密码二次确认，防止登录态被滥用转走资金
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
