<?php

namespace App\Http\Requests\Admin\V2\User;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\User;
use App\Support\AccountIdentifier;

class UpdateUserRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $phone = trim((string) $this->input('phone'));

            // 隐私脱敏回显值（含 *）视为“未修改”：直接剔除该字段，
            // 避免把 138****1234 这类脱敏串剥成 1381234 后当成真实号码覆盖入库。
            if (str_contains($phone, '*')) {
                $this->offsetUnset('phone');

                return;
            }

            $this->merge([
                'phone' => AccountIdentifier::normalizeOptionalPhone($phone),
            ]);
        }
    }

    public function rules(): array
    {
        // 管理端保存资料只做简单格式校验：
        // - 手机号可空、可留空清除，不强制必填；
        // - 不做内容级规则（11 位大陆号强格式、跨用户唯一性），
        //   否则无原始隐私权限的管理员保存脱敏回显、迁移/海外号码等场景都会无法保存。
        return array_merge([
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['nullable', 'in:0,1'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'agent_group_id' => ['nullable', 'integer', 'exists:agent_groups,id'],
        ], $this->allPaginationRules());
    }

    public function validatedPayload(): array
    {
        return $this->safe()->only([
            'nickname',
            'phone',
            'password',
            'status',
            'credit_limit',
            'admin_note',
            'agent_group_id',
        ]);
    }

    public function payload(): array
    {
        return $this->validatedPayload();
    }
}
