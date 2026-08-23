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
            'enabled' => ['required', 'boolean'],
            'admin_user_id' => ['required', 'integer', 'min:0'],
            'content' => ['nullable', 'string', 'max:5000'],
            'upstream_content' => ['nullable', 'string', 'max:5000'],
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
     * @return array{enabled: string, admin_user_id: string, content: string, upstream_content: string}
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'enabled' => filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'admin_user_id' => (string) max(0, (int) ($data['admin_user_id'] ?? 0)),
            'content' => TextSanitizer::clean((string) ($data['content'] ?? ''), true),
            'upstream_content' => TextSanitizer::clean((string) ($data['upstream_content'] ?? ''), true),
        ];
    }
}
