<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 管理端结算：金额由管理员填写（以本地账务可退上限为最终边界）。
 */
final class SettleApplicationRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => '请填写结算金额',
            'amount.numeric' => '结算金额必须是数字',
            'amount.min' => '结算金额必须大于 0',
            'amount.max' => '结算金额超出允许范围',
            'remark.max' => '结算备注最多 255 个字符',
        ];
    }
}
