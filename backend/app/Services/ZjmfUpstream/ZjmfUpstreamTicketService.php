<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\SecureAsset;
use App\Support\TextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 上游工单接口（被魔方财务对接）：/ticket/create、/ticket/reply。
 *
 * 魔方财务工单投递（ticketDeliver / ticketReplyDeliver）时序：
 *   POST /upload_image  -> {status, savename}，逐个上传附件
 *   POST /ticket/create -> {dptid, hostid, title, content, attachment[], priority, is_api}
 *                          期望 200 + data.tid，下游存为 host.upstream_tid
 *   POST /ticket/reply  -> {tid, content, attachment[], is_api}
 *                          期望 200，下游标记 is_deliver
 *
 * hostid 是下游存的 dcimid，即本系统 Service id；tid 即本系统 Ticket id。
 * 工单状态字面量对齐 TicketService：0=open 1=client_reply 2=staff_reply 3=closed。
 */
class ZjmfUpstreamTicketService
{
    private const STATUS_OPEN = 0;

    private const STATUS_CLIENT_REPLY = 1;

    private const STATUS_CLOSED = 3;

    /** 魔方 priority（1低/2中/3高）与本系统一致，钳位到合法区间 */
    private const PRIORITIES = [1, 2, 3, 4];

    /**
     * 下游投递工单：以 API 账号名义创建本系统工单并关联服务。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $title = TextSanitizer::clean((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            return ['status' => 400, 'msg' => '标题与内容不能为空'];
        }

        $service = Service::query()->where('user_id', (int) $user->id)->find((int) ($data['hostid'] ?? 0));
        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        $attachments = $this->normalizeAttachments($data['attachment'] ?? null);
        $priority = (int) ($data['priority'] ?? 2);
        $priority = in_array($priority, self::PRIORITIES, true) ? $priority : 2;

        try {
            $ticket = DB::transaction(function () use ($user, $service, $title, $content, $attachments, $priority): Ticket {
                $ticket = Ticket::create([
                    'user_id' => (int) $user->id,
                    'department' => 'support',
                    'subject' => $title,
                    'priority' => $priority,
                    'status' => self::STATUS_OPEN,
                    'service_id' => (int) $service->id,
                ]);

                TicketReply::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => (int) $user->id,
                    'content' => $content,
                    'is_staff' => 0,
                    'sender_type' => 'client',
                    'sender_name' => null,
                    'attachments' => $attachments,
                    'created_at' => now(),
                ]);

                return $ticket->fresh() ?? $ticket;
            });
        } catch (Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游工单创建失败', [
                'user_id' => (int) $user->id,
                'service_id' => (int) $service->id,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => '工单创建失败'];
        }

        return [
            'status' => 200,
            'msg' => '创建成功',
            'data' => ['tid' => (int) $ticket->id],
        ];
    }

    /**
     * 下游投递工单回复。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reply(User $user, array $data): array
    {
        $content = trim((string) ($data['content'] ?? ''));
        $attachments = $this->normalizeAttachments($data['attachment'] ?? null);
        if ($content === '' && $attachments === []) {
            return ['status' => 400, 'msg' => '回复内容不能为空'];
        }

        $ticket = Ticket::query()->where('user_id', (int) $user->id)->find((int) ($data['tid'] ?? 0));
        if (! $ticket instanceof Ticket) {
            return ['status' => 400, 'msg' => '工单不存在'];
        }
        if ((int) $ticket->status === self::STATUS_CLOSED) {
            return ['status' => 400, 'msg' => '工单已关闭'];
        }

        try {
            DB::transaction(function () use ($user, $ticket, $content, $attachments): void {
                TicketReply::create([
                    'ticket_id' => (int) $ticket->id,
                    'user_id' => (int) $user->id,
                    'content' => $content,
                    'is_staff' => 0,
                    'sender_type' => 'client',
                    'sender_name' => null,
                    'attachments' => $attachments,
                    'created_at' => now(),
                ]);

                $ticket->update(['status' => self::STATUS_CLIENT_REPLY]);
            });
        } catch (Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游工单回复失败', [
                'user_id' => (int) $user->id,
                'ticket_id' => (int) $ticket->id,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => '回复失败'];
        }

        return ['status' => 200, 'msg' => '回复成功'];
    }

    /**
     * 附件 savename -> 工单附件元数据。
     *
     * 只接受本系统 /upload_image 下发的 uploads/zjmf/ 路径，
     * 与 TicketService 附件元数据结构同构，展示层无需特判。
     *
     * @return list<array{name:string,path:string,size:int,mime_type:string,type:string}>
     */
    private function normalizeAttachments(mixed $attachment): array
    {
        $items = is_array($attachment) ? $attachment : array_filter([$attachment]);

        $result = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $path = trim($item);
            if ($path === '' || ! str_starts_with($path, 'uploads/zjmf/') || Str::contains($path, ['..', '\\'])) {
                continue;
            }

            try {
                $absolutePath = SecureAsset::absolutePath($path);
                if (! File::exists($absolutePath)) {
                    continue;
                }
                $mimeType = (string) (File::mimeType($absolutePath) ?: '');
                if (! str_starts_with($mimeType, 'image/')) {
                    continue;
                }

                $result[] = [
                    'name' => basename($path),
                    'path' => $path,
                    'size' => (int) File::size($absolutePath),
                    'mime_type' => $mimeType,
                    'type' => 'image',
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $result;
    }
}
