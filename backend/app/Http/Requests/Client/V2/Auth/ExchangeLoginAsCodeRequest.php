<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

/**
 * 管理员代登录 code 换 token 请求。
 *
 * 作为 Controller 内联 validate 迁移到 FormRequest 的示范：
 * - 校验规则集中在此，Controller 只消费 $request->validated()；
 * - 复用 ClientFormRequest 基类的 authorize=true（代登录实际鉴权在 AuthService 内做）。
 */
class ExchangeLoginAsCodeRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '代登录 code 不能为空',
            'code.min' => '代登录 code 格式不正确',
            'code.max' => '代登录 code 格式不正确',
        ];
    }
}
