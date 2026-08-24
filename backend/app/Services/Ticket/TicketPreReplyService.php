<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Models\AdminUser;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\AdminPermissions;
use App\Support\TextSanitizer;

class TicketPreReplyService
{
    public const SETTINGS_GROUP = 'ticket_pre_reply';

    public function __construct(
        private TicketDeliveryService $ticketDeliveryService,
    ) {}

    /**
     * 当前生效的预回复配置（settings 表优先，缺失时回退 config 默认值）。
     *
     * @return array{enabled: bool, admin_user_id: int, content: string, upstream_content: string}
     */
    public function config(): array
    {
        return [
            'enabled' => $this->enabled(),
            'admin_user_id' => $this->adminUserId(),
            'content' => (string) Setting::getValue(
                self::SETTINGS_GROUP,
                'content',
                (string) config('ticket_pre_reply.content', '')
            ),
            'upstream_content' => (string) Setting::getValue(
                self::SETTINGS_GROUP,
                'upstream_content',
                (string) config('ticket_pre_reply.upstream_content', '')
            ),
        ];
    }

    public function enabled(): bool
    {
        $value = Setting::getValue(
            self::SETTINGS_GROUP,
            'enabled',
            config('ticket_pre_reply.enabled', false)
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function adminUserId(): int
    {
        return (int) Setting::getValue(
            self::SETTINGS_GROUP,
            'admin_user_id',
            (string) config('ticket_pre_reply.admin_user_id', 0)
        );
    }

    /**
     * 客户新建工单后，以配置的管理员账号名义自动插入一条预回复，并把工单置为「员工回复」。
     *
     * 条件不满足（未启用、未配置管理员、可用内容为空、管理员不存在或已停用）时不创建，
     * 返回 null——预回复是自动化兜底，配置残缺时应保持静默，不产生无主回复。
     * 不推送上游、不触发通知，仅作为工单会话内可见的自动应答。
     */
    public function createAutoReply(Ticket $ticket): ?TicketReply
    {
        if (! $this->enabled()) {
            return null;
        }

        $adminUserId = $this->adminUserId();
        if ($adminUserId <= 0) {
            return null;
        }

        $content = $this->resolveContent($ticket);
        if ($content === '') {
            return null;
        }

        // 与工单指派的「员工回复」名单口径一致：仅启用且可回复工单的管理员
        // 才能作为预回复名义人，避免由不能回复工单的账号代发。
        $staff = AdminUser::query()->where('status', 1)->find($adminUserId);
        if ($staff === null || ! $staff->hasPermission(AdminPermissions::TICKET_REPLY)) {
            return null;
        }

        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $staff->id,
            'content' => $content,
            'is_staff' => 1,
            'sender_type' => 'admin',
            'sender_name' => $staff->nickname ?: $staff->username ?: '员工',
            'is_pre_reply' => 1,
            'attachments' => [],
            'created_at' => now(),
        ]);

        $ticket->update(['status' => TicketService::STATUS_STAFF_REPLY]);

        return $reply;
    }

    /**
     * 按工单是否命中上游传递规则选择回复内容：
     * 命中规则的工单优先用上游专用内容，未单独配置时回退普通内容；
     * 未命中规则的工单始终用普通内容。
     */
    private function resolveContent(Ticket $ticket): string
    {
        $config = $this->config();
        $content = TextSanitizer::clean($config['content'], true);
        $upstreamContent = TextSanitizer::clean($config['upstream_content'], true);

        // 未单独配置上游内容时无需做规则匹配：命中与否都回退普通内容，
        // 避免在建单事务内为无意义的判定延长持有时间。
        if ($upstreamContent === '') {
            return $content;
        }

        if ($this->ticketDeliveryService->matchesDeliveryRule($ticket)) {
            return $upstreamContent;
        }

        return $content;
    }
}
