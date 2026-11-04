<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 管理端驳回：必须写原因，台账要能追溯到「为什么驳回」。
 */
final class RejectApplicationRequest extends AdminFormRequest
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
            'remark.required' => '请填写驳回原因',
            'remark.max' => '驳回原因最多 255 个字符',
        ];
    }
}
