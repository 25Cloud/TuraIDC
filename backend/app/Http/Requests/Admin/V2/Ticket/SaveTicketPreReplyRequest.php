<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\AdminUser;
use App\Support\AdminPermissions;
use App\Support\TextSanitizer;
use Illuminate\Validation\Validator;

final class SaveTicketPreReplyRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            // enabled 必须提交；管理员与内容仅在提交时校验（停用开关的请求可以不携带，
            // 由控制器合并保留上次值，避免「禁用即清空已保存配置」）。
            'enabled' => ['required', 'boolean'],
            'admin_user_id' => ['sometimes', 'integer', 'min:0'],
            'content' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'upstream_content' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        // 停用时允许不配置管理员与内容（保留上次值，避免误清）；
        // 启用时普通内容必须非空（上游内容可选，未填时命中上游规则的工单回退普通内容），
        // 防止打开开关后预回复静默失效。
        $validator->after(function (Validator $validator): void {
            $data = $this->validated();
            $enabled = filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $enabled) {
                return;
            }

            $adminId = (int) ($data['admin_user_id'] ?? 0);
            if ($adminId <= 0) {
                $validator->errors()->add('admin_user_id', '启用预回复时必须选择管理员账号');
            } else {
                // 与运行时 createAutoReply 口径一致：仅启用且持 ticket.reply 权限的管理员
                // 才能作为预回复名义人，防止「保存成功但建单不生成预回复」的静默失效。
                $admin = AdminUser::query()->where('status', 1)->find($adminId);
                if ($admin === null || ! $admin->hasPermission(AdminPermissions::TICKET_REPLY)) {
                    $validator->errors()->add('admin_user_id', '所选管理员不存在、已停用或不可回复工单');
                }
            }

            // 与 payload() 使用同一 TextSanitizer::clean 转换后判断，纯标签/空白内容
            // 清洗后为空同样视为未填写，避免校验通过却存了空内容导致预回复静默失效。
            if (trim(TextSanitizer::clean((string) ($data['content'] ?? ''), true)) === '') {
                $validator->errors()->add('content', '启用预回复时必须填写回复内容');
            }
        });
    }

    /**
     * 仅返回请求中实际提交的字段（归一化后）；未提交的字段不进入结果，
     * 由控制器与旧配置合并，避免缺失字段被归一化为清空值覆盖已保存配置。
     *
     * @return array<string, string>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $payload = [];

        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        if (array_key_exists('admin_user_id', $data)) {
            $payload['admin_user_id'] = (string) max(0, (int) $data['admin_user_id']);
        }
        if (array_key_exists('content', $data)) {
            $payload['content'] = TextSanitizer::clean((string) $data['content'], true);
        }
        if (array_key_exists('upstream_content', $data)) {
            $payload['upstream_content'] = TextSanitizer::clean((string) $data['upstream_content'], true);
        }

        return $payload;
    }
}
