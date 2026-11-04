<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 管理端作废：卡死（典型是「结果未知」）时的人工收口出口，必须写原因。
 */
final class CloseApplicationRequest extends AdminFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'remark' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remark.required' => '请填写作废原因',
            'remark.max' => '作废原因最多 255 个字符',
        ];
    }
}
