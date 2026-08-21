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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
        $rawAttachments = $this->rawAttachments($payload);
        $hasAttachments = $rawAttachments !== [];
        if ($rawAttachments === []) {
            Log::info('上游工单回调未识别到附件', [
                'payload_keys' => array_values(array_keys($payload)),
                'attachment_types' => $this->attachmentFieldTypes($payload),
            ]);
        }
        throw_if($legacy && trim((string) ($payload['id'] ?? '')) === '', new BusinessException('回调服务标识不能为空', 42200));
        throw_if($legacy && trim((string) ($payload['rand_str'] ?? '')) === '', new BusinessException('回调随机串不能为空', 42200));
        throw_if($upstreamTicketId === '', new BusinessException('上游工单号不能为空', 42200));
        throw_if($content === '' && ! $hasAttachments, new BusinessException('上游回复内容或图片至少填写一项', 42200));

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
            $replyId = trim((string) ($payload['rid'] ?? ''));
            $eventId = 'legacy:'.hash('sha256', implode('|', [
                (string) $binding->provider_key,
                (string) $binding->supplier_id,
                $upstreamTicketId,
                $replyId !== '' ? 'rid:'.$replyId : 'rand:'.(string) ($payload['rand_str'] ?? ''),
            ]));
        }
        if ($eventId === '') {
            $eventId = hash('sha256', implode('|', [
                $upstreamTicketId,
                $content,
                json_encode($rawAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]));
        }

        $duplicate = false;
        $replyId = null;
        // 附件校验/归一化到 TicketReply::create() 落库（事务提交）期间，持有按文件名共享的缓存锁，
        // 与 cleanupOrphanUploads() 使用同一把锁：清理任务在锁内重新查询引用后再删除，
        // 避免其在校验后、引用写入数据库前误删文件，导致记录指向已删除文件。
        // 锁在事务提交后才释放，避免清理任务在提交瞬间读到尚未落库的引用结论。
        // 锁 TTL 仅作防死锁兜底（60s 远大于回调事务耗时），一致性边界是事务提交后的显式释放而非 TTL。
        $locks = $this->acquireInboundAttachmentLocks($rawAttachments);
        try {
            DB::transaction(function () use ($binding, $payload, $content, $eventId, $rawAttachments, &$duplicate, &$replyId): void {
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
                $attachments = $this->normalizeInboundAttachments($rawAttachments);
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
        } finally {
            $this->releaseInboundAttachmentLocks($locks);
        }

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
    private function rawAttachments(array $payload): array
    {
        $raw = [];
        foreach (['attachment', 'attachments', 'images', 'image'] as $key) {
            if (! array_key_exists($key, $payload) || $this->isEmptyAttachmentValue($payload[$key])) {
                continue;
            }
            $raw = $payload[$key];
            break;
        }
        if ($raw === [] && is_array($payload['data'] ?? null)) {
            foreach (['attachment', 'attachments', 'images', 'image'] as $key) {
                if (array_key_exists($key, $payload['data']) && ! $this->isEmptyAttachmentValue($payload['data'][$key])) {
                    $raw = $payload['data'][$key];
                    break;
                }
            }
        }
        for ($depth = 0; $depth < 3; $depth++) {
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = $decoded ?? $raw;
            }
            if (! is_array($raw)) {
                break;
            }
            foreach (['data', 'list', 'items', 'attachment', 'attachments', 'images', 'image'] as $key) {
                if (array_key_exists($key, $raw) && count($raw) === 1) {
                    $raw = $raw[$key];
                    continue 2;
                }
            }
            break;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            return [$raw];
        }
        if ($raw === [] || array_is_list($raw)) {
            return $raw;
        }
        if (array_intersect(array_keys($raw), ['savename', 'save_name', 'filename', 'file_name', 'name']) !== []) {
            return [$raw];
        }
        if (count(array_filter($raw, 'is_scalar')) === count($raw)) {
            return array_values($raw);
        }

        return [$raw];
    }

    /** @return array<string, string> */
    private function attachmentFieldTypes(array $payload): array
    {
        $result = [];
        foreach (['attachment', 'attachments', 'images', 'image', 'data'] as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                $result[$key] = is_array($value) ? 'array' : get_debug_type($value);
            }
        }

        return $result;
    }

    private function isEmptyAttachmentValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
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
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        throw_if(! is_array($raw), new BusinessException('上游附件格式无效', 42200));
        throw_if(count($raw) > 9, new BusinessException('上游附件数量超限', 42200));

        $attachments = [];
        foreach ($raw as $item) {
            $filename = $this->localAttachmentFilename($item);
            throw_if($filename === '' || basename($filename) !== $filename || str_contains($filename, '..'), new BusinessException('上游附件名称无效', 42200));
            $path = 'private/tickets/upstream/'.$filename;
            $absolutePath = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $path));
            throw_if(! File::exists($absolutePath), new BusinessException('上游附件不存在', 42200));
            throw_if((int) File::size($absolutePath) > 5 * 1024 * 1024, new BusinessException('上游附件大小超限', 42200));
            $mimeType = strtolower((string) File::mimeType($absolutePath));
            throw_if(! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), new BusinessException('上游附件类型无效', 42200));
            $name = is_array($item) ? trim((string) ($item['name'] ?? $filename)) : $filename;
            $attachments[] = [
                'name' => $name !== '' ? basename($name) : $filename,
                'path' => $path,
                'size' => (int) File::size($absolutePath),
                'mime_type' => $mimeType,
                'type' => 'image',
            ];
        }

        return $attachments;
    }

    /**
     * 从回调原始附件数据中提取候选文件名（去重），用于构造与清理任务一致的按文件名共享锁。
     * 只负责确定锁键，名称合法性仍由 normalizeInboundAttachments() 校验。
     *
     * @param  mixed  $raw  回调原始附件数据
     * @return list<string>
     */
    private function inboundAttachmentFilenames(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (! is_array($raw)) {
            return [];
        }

        $filenames = [];
        foreach ($raw as $item) {
            $filename = $this->localAttachmentFilename($item);
            if ($filename !== '') {
                $filenames[] = $filename;
            }
        }

        return array_values(array_unique($filenames));
    }

    /**
     * 按附件文件名逐个获取共享缓存锁；已获取的锁由调用方在 finally 中配对释放。
     * 任一文件锁等待超时（cleanupOrphanUploads() 正持有锁）时，释放已获取的锁并抛出可重试的业务异常。
     * 锁 TTL 为防死锁兜底，一致性由调用方在事务提交后的 finally 显式释放保证。
     *
     * @param  mixed  $raw  回调原始附件数据
     * @return list<\Illuminate\Contracts\Cache\Lock>
     */
    private function acquireInboundAttachmentLocks(mixed $raw): array
    {
        $locks = [];
        try {
            // 先排序再统一顺序获取，避免两个并发回调以相反顺序取同一批附件锁时互相等待。
            $filenames = $this->inboundAttachmentFilenames($raw);
            sort($filenames, SORT_STRING);
            foreach ($filenames as $filename) {
                $lock = Cache::lock('ticket-upstream-upload:'.$filename, 60);
                $lock->block(10);
                $locks[] = $lock;
            }
        } catch (LockTimeoutException $exception) {
            foreach ($locks as $acquired) {
                $acquired->release();
            }

            throw new BusinessException('上游附件正在处理，请稍后重试', 42900);
        }

        return $locks;
    }

    /**
     * 释放 receiveReply() 持有的附件缓存锁，与 acquireInboundAttachmentLocks() 配对使用。
     *
     * @param  list<\Illuminate\Contracts\Cache\Lock>  $locks
     */
    private function releaseInboundAttachmentLocks(array $locks): void
    {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }

    private function localAttachmentFilename(mixed $item): string
    {
        if (is_string($item)) {
            return trim($item);
        }
        if (! is_array($item)) {
            return '';
        }

        foreach (['savename', 'save_name', 'filename', 'file_name', 'name'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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

    /**
     * 清理上游附件上传目录中的孤儿文件：超过保留期且未被任何工单回复引用的文件会被删除。
     * 对每个待删文件先获取与 receiveReply() 共享的按文件名缓存锁，并在锁内重新查询引用后再删除，
     * 避免回调刚校验附件但引用尚未写入数据库时误删文件；File::delete 返回 false 时计入 errors。
     * 锁被回调持有而跳过删除的文件计入 skipped（此时引用结论未知，不归入 referenced）。
     * 上游系统无法强制携带上传凭证时，该任务用于缓解匿名上传造成的磁盘占用。
     *
     * @return array{checked: int, deleted: int, referenced: int, errors: int, skipped: int}
     */
    public function cleanupOrphanUploads(?int $retentionMinutes = null, ?string $directory = null, int $limit = 100): array
    {
        $retentionMinutes = $retentionMinutes ?? (int) config('ticket_upstream.upload_unused_retention_minutes', 5);
        $directory = $directory ?? storage_path('app/private/tickets/upstream');
        if (! is_dir($directory)) {
            return ['checked' => 0, 'deleted' => 0, 'referenced' => 0, 'errors' => 0, 'skipped' => 0];
        }

        $cutoff = now()->subMinutes($retentionMinutes);
        $files = collect(File::files($directory))
            ->sortBy(fn (\SplFileInfo $file): int => $file->getMTime())
            ->values();
        $checked = 0;
        $deleted = 0;
        $referenced = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($files as $file) {
            if ($deleted >= $limit) {
                break;
            }
            if (now()->createFromTimestamp($file->getMTime())->gt($cutoff)) {
                continue;
            }
            $checked++;
            $lock = Cache::lock('ticket-upstream-upload:'.$file->getFilename(), 60);
            try {
                // 回调正在处理该文件（锁被持有）时跳过本次删除，等待下轮扫描。
                // 此时数据库可能还没有附件引用，不能计入 referenced，单独归入 skipped。
                if (! $lock->get()) {
                    $skipped++;

                    continue;
                }
                // 引用结论必须在锁内重新查询：锁外查到的结果在竞态窗口内可能已经过期
                $path = 'private/tickets/upstream/'.$file->getFilename();
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path);
                if (TicketReply::query()->where('attachments', 'like', "%{$escaped}%")->exists()) {
                    $referenced++;

                    continue;
                }
                if (File::delete($file->getPathname())) {
                    $deleted++;
                    Log::info('清理上游未使用上传文件', ['filename' => $file->getFilename()]);
                } else {
                    $errors++;
                    Log::warning('清理上游未使用上传文件删除失败', ['filename' => $file->getFilename()]);
                }
            } catch (\Throwable $exception) {
                $errors++;
                Log::warning('清理上游未使用上传文件失败', [
                    'filename' => $file->getFilename(),
                    'message' => $exception->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        return [
            'checked' => $checked,
            'deleted' => $deleted,
            'referenced' => $referenced,
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }
}
