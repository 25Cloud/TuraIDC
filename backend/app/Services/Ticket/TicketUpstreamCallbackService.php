<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Constants\UserNotificationType;
use App\Exceptions\BusinessException;
use App\Models\TicketReply;
use App\Models\TicketReplyDelivery;
use App\Models\TicketUpstreamBinding;
use App\Services\Notification\UserNotificationService;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketService;
use App\Services\Upstream\ProviderKey;
use App\Support\TextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class TicketUpstreamCallbackService
{
    public function __construct(
        private readonly UserNotificationService $notifications,
        private readonly TicketDeliveryService $delivery,
    ) {}

    /**
     * 接收上游客服回复。回调消息只写入本地，不进入出站队列，避免上下游循环。
     *
     * @param  array<string, mixed>  $payload
     * @return array{accepted: bool, duplicate: bool, reply_id: int|null}
     */
    public function receiveReply(array $payload, bool $legacy = true): array
    {
        $upstreamTicketId = trim((string) ($payload['tid'] ?? ''));
        $content = TextSanitizer::cleanHtml((string) ($payload['content'] ?? ''), true);
        throw_if($legacy && trim((string) ($payload['id'] ?? '')) === '', new BusinessException('回调服务标识不能为空', 42200));
        throw_if($legacy && trim((string) ($payload['rand_str'] ?? '')) === '', new BusinessException('回调随机串不能为空', 42200));
        throw_if($upstreamTicketId === '', new BusinessException('上游工单号不能为空', 42200));
        throw_if($content === '', new BusinessException('上游回复内容不能为空', 42200));

        $binding = TicketUpstreamBinding::query()
            ->with('ticket.service')
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('upstream_ticket_id', $upstreamTicketId)
            ->first();
        throw_if($binding === null, new BusinessException('未找到对应的本地工单', 40400));
        if ($legacy) {
            throw_if((int) ($payload['id'] ?? 0) !== (int) ($binding->ticket?->service_id ?? 0), new BusinessException('回调服务与工单不匹配', 40300));
        }
        $this->verifySignature($payload, $binding, $legacy);
        $supplierEnabled = DB::table('suppliers')->where('id', $binding->supplier_id)->where('status', 1)->exists()
            && DB::table('supplier_plugin_bindings')
                ->where('supplier_id', $binding->supplier_id)
                ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
                ->where('status', 1)
                ->exists();
        throw_if(! $supplierEnabled, new BusinessException('上游供应商已停用', 42200));

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId === '' && $legacy) {
            $eventId = 'legacy:'.hash('sha256', implode('|', [(string) ($payload['id'] ?? ''), (string) ($payload['rand_str'] ?? '')]));
        }
        if ($eventId === '') {
            $eventId = hash('sha256', implode('|', [
                $upstreamTicketId,
                $content,
                json_encode($payload['attachment'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]));
        }

        $duplicate = false;
        $replyId = null;
        DB::transaction(function () use ($binding, $payload, $content, $eventId, &$duplicate, &$replyId): void {
            $binding = TicketUpstreamBinding::query()->lockForUpdate()->findOrFail($binding->id);
            $existing = TicketReplyDelivery::query()
                ->where('direction', TicketDeliveryService::DIRECTION_INBOUND)
                ->where('remote_event_id', $eventId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $duplicate = true;
                $replyId = (int) $existing->ticket_reply_id;

                return;
            }

            $ticket = $binding->ticket()->lockForUpdate()->firstOrFail();
            $attachments = $this->normalizeInboundAttachments($payload['attachment'] ?? []);
            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => 0,
                'content' => $content,
                'is_staff' => true,
                'sender_type' => 'upstream_admin',
                'sender_name' => trim((string) ($payload['admin_name'] ?? '')) ?: '上游客服',
                'attachments' => $attachments,
                'created_at' => now(),
            ]);
            TicketReplyDelivery::create([
                'ticket_reply_id' => $reply->id,
                'direction' => TicketDeliveryService::DIRECTION_INBOUND,
                'content_prefix' => null,
                'status' => 'delivered',
                'idempotency_key' => 'inbound:'.$eventId,
                'remote_event_id' => $eventId,
                'delivered_at' => now(),
            ]);
            $ticket->update(['status' => TicketService::STATUS_STAFF_REPLY]);
            $replyId = (int) $reply->id;
        });

        $ticket = $binding->ticket()->first();
        if ($ticket !== null) {
            $this->delivery->recordInboundCallbackLog($ticket, [
                'ticket_reply_id' => $replyId,
                'event' => $duplicate ? 'duplicate' : 'succeeded',
                'status' => 'delivered',
                'reason_code' => $duplicate ? 'duplicate_event' : null,
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => $duplicate ? '上游工单回复重复回调，已按幂等结果处理' : '已接收上游工单回复',
            ]);
        }
        if (! $duplicate && $ticket !== null) {
            $this->notifications->create(
                (int) $ticket->user_id,
                UserNotificationType::TICKET_STAFF_REPLY,
                '工单收到上游回复',
                '工单「'.(string) $ticket->subject.'」收到上游管理员回复',
                '/client/tickets/'.(int) $ticket->id,
                ['ticket_id' => (int) $ticket->id]
            );
        }

        return ['accepted' => true, 'duplicate' => $duplicate, 'reply_id' => $replyId];
    }

    /** @param array<string, mixed> $payload */
    private function verifySignature(array $payload, TicketUpstreamBinding $binding, bool $legacy): void
    {
        $provided = trim((string) ($payload['signature'] ?? ''));
        throw_if($provided === '', new BusinessException('上游回调签名不能为空', 40100));

        $secret = $this->legacyToken($binding);
        if ($legacy) {
            throw_if($secret === '', new BusinessException('上游回调 token 未配置', 40100));
            $signed = [
                'id' => (string) ($payload['id'] ?? ''),
                'token' => $secret,
                'rand_str' => (string) ($payload['rand_str'] ?? ''),
            ];
            ksort($signed, SORT_STRING);
            $expected = strtoupper(md5((string) json_encode($signed)));
            throw_unless(hash_equals($expected, strtoupper($provided)), new BusinessException('上游回调签名无效', 40100));

            return;
        }

        $secret = trim((string) config('ticket_upstream.callback_secret', ''));
        throw_if($secret === '', new BusinessException('新版上游回调签名未配置', 40100));
        $signed = $payload;
        unset($signed['signature']);
        ksort($signed);
        $expected = hash_hmac('sha256', (string) json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
        throw_unless(hash_equals($expected, $provided), new BusinessException('上游回调签名无效', 40100));
    }

    /** @return list<array<string, mixed>> */
    private function normalizeInboundAttachments(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        throw_if(! is_array($raw), new BusinessException('上游附件格式无效', 42200));
        throw_if(count($raw) > 9, new BusinessException('上游附件数量超限', 42200));

        $attachments = [];
        foreach ($raw as $item) {
            $filename = is_string($item) ? trim($item) : '';
            throw_if($filename === '' || basename($filename) !== $filename || str_contains($filename, '..'), new BusinessException('上游附件名称无效', 42200));
            $path = 'private/tickets/upstream/'.$filename;
            $absolutePath = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $path));
            throw_if(! File::exists($absolutePath), new BusinessException('上游附件不存在', 42200));
            throw_if((int) File::size($absolutePath) > 5 * 1024 * 1024, new BusinessException('上游附件大小超限', 42200));
            $mimeType = strtolower((string) File::mimeType($absolutePath));
            throw_if(! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), new BusinessException('上游附件类型无效', 42200));
            $attachments[] = [
                'name' => $filename,
                'path' => $path,
                'size' => (int) File::size($absolutePath),
                'mime_type' => $mimeType,
                'type' => 'image',
            ];
        }

        return $attachments;
    }

    private function legacyToken(TicketUpstreamBinding $binding): string
    {
        $service = $binding->ticket?->service;
        if ($service === null) {
            return '';
        }

        $serviceId = (int) ($service->id ?? 0);

        return $serviceId > 0 ? TicketUpstreamCallbackToken::forServiceId($serviceId) : '';
    }
}
