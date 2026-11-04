<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Client;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

/**
 * 提交退款申请。
 *
 * `service_id` 只做「是不是本人服务」的第二道保险——真正的归属校验在服务层按
 * `user_id` 过滤，绝不能只依赖这里的参数校验。
 */
final class SubmitApplicationRequest extends ClientFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.required' => '请选择要申请退款的服务',
            'service_id.integer' => '服务标识不正确',
            'reason.required' => '请填写退款原因',
            'reason.max' => '退款原因最多 500 个字符',
        ];
    }
}
