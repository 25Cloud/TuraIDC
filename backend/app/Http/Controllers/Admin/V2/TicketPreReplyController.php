<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Ticket\SaveTicketPreReplyRequest;
use App\Http\Resources\Ticket\V2\TicketAdminUserOptionResource;
use App\Models\Setting;
use App\Services\System\OperationLogService;
use App\Services\Ticket\TicketPreReplyService;
use App\Services\Ticket\TicketService;
use Illuminate\Http\JsonResponse;

final class TicketPreReplyController extends Controller
{
    public function __construct(
        private readonly OperationLogService $operationLogService,
    ) {}

    public function show(TicketPreReplyService $preReplyService, TicketService $ticketService): JsonResponse
    {
        return $this->success([
            'settings' => $preReplyService->config(),
            // 预回复以管理员名义发送，候选人限定为可回复工单的启用管理员，
            // 与工单指派的「员工回复」名单口径一致。
            'admin_users' => TicketAdminUserOptionResource::collection(
                $ticketService->adminAssignableUsers()
            )->resolve(),
        ]);
    }

    public function save(SaveTicketPreReplyRequest $request, TicketPreReplyService $preReplyService): JsonResponse
    {
        $payload = $request->payload();
        // 写入前读取旧配置并归一化为字符串形态，供审计做前后对照。
        $before = $preReplyService->config();
        $baseline = [
            'enabled' => $before['enabled'] ? '1' : '0',
            'admin_user_id' => (string) $before['admin_user_id'],
            'content' => (string) $before['content'],
            'upstream_content' => (string) $before['upstream_content'],
        ];

        // 请求只更新实际提交的字段（如仅关闭开关时不携带管理员与内容），
        // 未提交字段保留旧值，避免「禁用即清空已保存配置」。
        $merged = array_merge($baseline, $payload);

        Setting::setValues(TicketPreReplyService::SETTINGS_GROUP, $merged);

        $this->operationLogService->write(
            userId: (int) ($request->user()?->id ?? 0),
            userType: 'admin',
            action: 'ticket.pre_reply.update',
            module: 'ticket',
            targetId: 0,
            detail: [
                'title' => '工单预回复设置更新',
                'before' => $baseline,
                'after' => $merged,
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? ''),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ],
            ipAddress: (string) $request->ip(),
        );

        return $this->success($merged, '工单预回复设置已保存');
    }
}
