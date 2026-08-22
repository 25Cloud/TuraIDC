<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Exceptions\BusinessException;
use App\Jobs\DeliverTicketReplyToUpstreamJob;
use App\Jobs\DeliverTicketToUpstreamJob;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketDeliveryRule;
use App\Models\TicketReply;
use App\Models\TicketReplyDelivery;
use App\Models\TicketUpstreamBinding;
use App\Models\TicketUpstreamDeliveryLog;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\ProviderKey;
use App\Support\PublicUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;

class TicketDeliveryService
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public function __construct(
        private readonly PluginBindingResolver $bindings,
    ) {}

    /**
     * @return array<int, array{id: string, name: string, description: string}>
     */
    public function upstreamDepartments(int $supplierId): array
    {
        $supplier = Supplier::query()->find($supplierId);
        throw_if($supplier === null || (int) $supplier->status !== 1, new BusinessException('供应商不存在或未启用', 42200));

        $supplier = $this->bindings->supplierWithRuntimeCredentials($supplier, true, ProviderKey::ZJMF_FINANCE_API);
        $binding = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('status', 1)
            ->first(['id']);
        throw_if($binding === null, new BusinessException('供应商未配置启用的 ZJMF 财务接口绑定', 42200));

        return $this->zjmfTransport()->getTicketDepartments($supplier);
    }

    public function registerTicketCallback(Ticket $ticket): void
    {
        $ticket->loadMissing(['service', 'upstreamBinding']);
        $binding = $ticket->upstreamBinding;
        if (! $binding instanceof TicketUpstreamBinding
            || $binding->provider_key !== ProviderKey::ZJMF_FINANCE_API
            || trim((string) $binding->upstream_ticket_id) === ''
        ) {
            throw new BusinessException('工单尚未形成可注册回调的上游绑定', 42200);
        }

        $service = $ticket->service;
        throw_if($service === null, new BusinessException('工单关联服务不存在，无法注册回调', 42200));
        $supplier = Supplier::query()->find((int) $binding->supplier_id);
        throw_if($supplier === null || (int) $supplier->status !== 1, new BusinessException('上游供应商不存在或未启用', 42200));
        $supplier = $this->bindings->supplierWithRuntimeCredentials($supplier, true, ProviderKey::ZJMF_FINANCE_API);

        try {
            $response = $this->registerTicketCallbackRequest($supplier, $service, $binding);
            throw_if($this->extractSuccess($response) === false, new BusinessException((string) ($response['msg'] ?? '注册上游回调失败'), 42200));
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.callback_registration',
                'event' => 'succeeded',
                'status' => 'delivered',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '上游工单回调已重新注册',
            ]);
        } catch (\Throwable $e) {
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.callback_registration',
                'event' => 'failed',
                'status' => 'failed',
                'reason_code' => 'callback_registration_failed',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function recordInboundCallbackLog(Ticket $ticket, array $data): void
    {
        $this->recordLog($ticket, array_merge([
            'direction' => self::DIRECTION_INBOUND,
            'operation' => 'ticket.callback',
        ], $data));
    }

    public function recordInboundCallbackFailure(string $upstreamTicketId, string $reasonCode, string $message): void
    {
        try {
            $binding = TicketUpstreamBinding::query()
                ->with('ticket')
                ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
                ->where('upstream_ticket_id', trim($upstreamTicketId))
                ->first();
            if ($binding?->ticket instanceof Ticket) {
                $this->recordInboundCallbackLog($binding->ticket, [
                    'event' => 'failed',
                    'status' => 'failed',
                    'reason_code' => $reasonCode,
                    'provider_key' => $binding->provider_key,
                    'supplier_id' => $binding->supplier_id,
                    'message' => mb_substr($message, 0, 2000),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('记录上游工单回调失败事件异常', [
                'upstream_ticket_id' => $upstreamTicketId !== '' ? $upstreamTicketId : null,
                'reason_code' => $reasonCode,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketStatus(Ticket $ticket): array
    {
        $binding = $ticket->upstreamBinding;
        $latestLog = $this->logsQuery($ticket)->latest('occurred_at')->latest('id')->first();
        $diagnostic = null;
        $status = $binding?->status;
        if ($status === null) {
            $decision = $this->resolveContextDecision($ticket);
            if ($decision['context'] === null) {
                $diagnostic = [
                    'event' => 'skipped',
                    'status' => 'skipped',
                    'reason_code' => $decision['reason_code'],
                    'message' => $decision['message'],
                    'occurred_at' => null,
                ];
            }
            $status = 'not_configured';
        }
        $lastEvent = $latestLog ? [
            'event' => (string) $latestLog->event,
            'status' => (string) $latestLog->status,
            'reason_code' => $latestLog->reason_code,
            'message' => $latestLog->message,
            'occurred_at' => $latestLog->occurred_at?->format('Y-m-d H:i:s'),
        ] : $diagnostic;

        return [
            'configured' => $binding instanceof TicketUpstreamBinding,
            'status' => (string) $status,
            'status_label' => $this->statusLabel((string) $status),
            'provider_key' => $binding?->provider_key,
            'supplier_id' => $binding?->supplier_id ? (int) $binding->supplier_id : null,
            'upstream_department_id' => $binding?->upstream_department_id,
            'upstream_service_id' => $binding?->upstream_service_id,
            'upstream_ticket_id' => $binding?->upstream_ticket_id,
            'attempts' => (int) ($binding?->attempts ?? 0),
            'last_attempt_at' => $binding?->last_attempt_at?->format('Y-m-d H:i:s'),
            'delivered_at' => $binding?->delivered_at?->format('Y-m-d H:i:s'),
            'last_error' => $binding?->last_error,
            'last_event' => $lastEvent,
        ];
    }

    public function deliveryLogs(Ticket $ticket, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));
        $query = $this->logsQuery($ticket)->latest('occurred_at')->latest('id');
        if ($query->exists()) {
            return $query->paginate($perPage);
        }

        $decision = $this->resolveContextDecision($ticket);
        if ($decision['context'] !== null) {
            return new LengthAwarePaginatorInstance([], 0, $perPage, 1, [
                'path' => request()->url(),
            ]);
        }

        $virtual = new TicketUpstreamDeliveryLog([
            'id' => 0,
            'ticket_id' => $ticket->id,
            'direction' => self::DIRECTION_OUTBOUND,
            'operation' => 'ticket.create',
            'event' => 'skipped',
            'status' => 'skipped',
            'reason_code' => $decision['reason_code'],
            'provider_key' => $decision['provider_key'],
            'supplier_id' => $decision['supplier_id'],
            'message' => $decision['message'],
            'occurred_at' => null,
        ]);

        return new LengthAwarePaginatorInstance([$virtual], 1, $perPage, 1, [
            'path' => request()->url(),
        ]);
    }

    public function queueTicket(Ticket $ticket): void
    {
        $decision = $this->resolveContextDecision($ticket);
        $context = $decision['context'];
        if ($context === null) {
            $this->recordLog($ticket, [
                'operation' => 'ticket.create',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => $decision['reason_code'],
                'provider_key' => $decision['provider_key'],
                'supplier_id' => $decision['supplier_id'],
                'message' => $decision['message'],
            ]);

            return;
        }

        $binding = TicketUpstreamBinding::query()->firstOrCreate(
            ['ticket_id' => $ticket->id],
            [
                'provider_key' => $context['provider_key'],
                'supplier_id' => $context['supplier_id'],
                'upstream_department_id' => $context['upstream_department_id'],
                'upstream_service_id' => $context['upstream_service_id'],
                'status' => 'pending',
            ]
        );
        if ($binding->upstream_ticket_id !== null || in_array($binding->status, ['sending', 'delivered'], true)) {
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'skipped',
                'status' => $binding->status,
                'reason_code' => 'already_delivered',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '工单已经完成上游投递，无需重复提交',
            ]);

            return;
        }
        $binding->update(['status' => 'pending', 'last_error' => null]);
        $this->recordLog($ticket, [
            'binding_id' => $binding->id,
            'operation' => 'ticket.create',
            'event' => 'queued',
            'status' => 'pending',
            'provider_key' => $binding->provider_key,
            'supplier_id' => $binding->supplier_id,
            'message' => '工单已进入上游投递队列',
        ]);

        DeliverTicketToUpstreamJob::dispatch((int) $ticket->id)->afterCommit();
    }

    public function queueClientReply(TicketReply $reply): void
    {
        $ticket = $reply->relationLoaded('ticket') ? $reply->ticket : $reply->load('ticket')->ticket;
        if (! $ticket instanceof Ticket) {
            return;
        }
        $binding = $ticket->upstreamBinding()->first();
        if (! $binding instanceof TicketUpstreamBinding) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'binding_missing',
                'message' => '工单尚未成功提交到上游，客户回复暂不转发',
            ]);

            return;
        }

        $this->queueReply($reply, '[下游用户消息]');
    }

    public function queueStaffReply(TicketReply $reply): void
    {
        $ticket = $reply->relationLoaded('ticket') ? $reply->ticket : $reply->load('ticket')->ticket;
        if (! $ticket instanceof Ticket) {
            return;
        }

        $binding = $ticket->upstreamBinding()->first();
        if (! $binding instanceof TicketUpstreamBinding) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'binding_missing',
                'message' => '工单尚未成功提交到上游，管理员回复暂不转发',
            ]);

            return;
        }
        if (! $this->syncAdminReplies($binding, $ticket)) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'binding_id' => $binding->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'admin_reply_sync_disabled',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '当前规则未开启管理员回复同步',
            ]);

            return;
        }

        $this->queueReply($reply, '[下游管理员消息]');
    }

    public function deliverTicket(int $ticketId): void
    {
        $ticket = Ticket::query()->with(['service', 'upstreamBinding'])->find($ticketId);
        if (! $ticket instanceof Ticket) {
            Log::warning('工单上游投递任务找不到工单', ['ticket_id' => $ticketId]);

            return;
        }

        $binding = $ticket->upstreamBinding;
        if (! $binding instanceof TicketUpstreamBinding) {
            $this->recordLog($ticket, [
                'operation' => 'ticket.create',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'binding_missing',
                'message' => '工单没有待投递的上游绑定',
            ]);

            return;
        }
        if ($binding->provider_key !== ProviderKey::ZJMF_FINANCE_API) {
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'provider_mismatch',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '工单绑定的上游接口不支持投递',
            ]);

            return;
        }
        $decision = $this->resolveContextDecision($ticket);
        if ($decision['context'] === null) {
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => $decision['reason_code'],
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => $decision['message'],
            ]);

            return;
        }
        if ($binding->upstream_ticket_id !== null) {
            return;
        }
        $claimed = TicketUpstreamBinding::query()
            ->whereKey($binding->id)
            ->whereNull('upstream_ticket_id')
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('last_attempt_at', '<', now()->subMinutes(10)));
            })
            ->update(['status' => 'sending', 'last_attempt_at' => now()]);
        if ($claimed !== 1) {
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'claim_skipped',
                'status' => (string) $binding->status,
                'reason_code' => 'queue_claim_failed',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'attempt' => (int) $binding->attempts,
                'message' => '投递任务未能获取工单发送锁，可能已有任务正在处理',
            ]);

            return;
        }
        $binding->refresh();
        $this->recordLog($ticket, [
            'binding_id' => $binding->id,
            'operation' => 'ticket.create',
            'event' => 'sending',
            'status' => 'sending',
            'provider_key' => $binding->provider_key,
            'supplier_id' => $binding->supplier_id,
            'attempt' => (int) $binding->attempts + 1,
            'message' => '开始提交工单到上游',
        ]);

        $this->attemptBinding($binding, function () use ($ticket, $binding): string {
            $service = $ticket->service;
            throw_if($service === null, new BusinessException('工单关联服务不存在', 42200));

            $supplier = Supplier::query()->find($binding->supplier_id);
            throw_if($supplier === null, new BusinessException('工单上游供应商未配置', 42200));
            $supplier = $this->bindings->supplierWithRuntimeCredentials($supplier, true, ProviderKey::ZJMF_FINANCE_API);
            $this->registerTicketCallbackRequest($supplier, $service, $binding);
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.callback_registration',
                'event' => 'succeeded',
                'status' => 'delivered',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '已注册上游工单回调地址 /api/ticket_reply/sync',
            ]);

            $firstReply = $ticket->replies()->oldest('id')->first();
            $content = (string) ($firstReply?->content ?? '');
            $attachments = (array) ($firstReply?->attachments ?? []);
            $payload = [
                'dptid' => $binding->upstream_department_id,
                'hostid' => $binding->upstream_service_id,
                'title' => (string) $ticket->subject,
                'content' => $content,
                'attachment' => $this->uploadAttachments($supplier, $attachments),
                'priority' => $this->priorityLabel((int) $ticket->priority),
                'is_api' => 1,
                'request_id' => 'ticket-create:'.$binding->id,
            ];

            $response = $this->zjmfTransport()->post($supplier, '/ticket/create', $payload);
            $upstreamId = $this->extractRemoteId($response);
            throw_if($upstreamId === null, new BusinessException((string) ($response['msg'] ?? '上游工单创建失败'), 42200));

            return $upstreamId;
        }, function (string $remoteId) use ($binding, $ticket): void {
            $updated = TicketUpstreamBinding::query()
                ->whereKey($binding->id)
                ->where('status', 'sending')
                ->whereNull('upstream_ticket_id')
                ->update([
                    'upstream_ticket_id' => $remoteId,
                    'status' => 'delivered',
                    'delivered_at' => now(),
                ]);
            if ($updated !== 1) {
                $this->recordLog($ticket, [
                    'binding_id' => $binding->id,
                    'operation' => 'ticket.create',
                    'event' => 'late_success',
                    'status' => (string) ($binding->fresh()?->status ?? 'unknown'),
                    'reason_code' => 'stale_claim_completed',
                    'provider_key' => $binding->provider_key,
                    'supplier_id' => $binding->supplier_id,
                    'message' => '投递任务返回成功，但本地绑定已由其他任务处理',
                ]);

                return;
            }
            $binding->refresh();
            $this->recordLog($ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'succeeded',
                'status' => 'delivered',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'attempt' => (int) $binding->attempts,
                'message' => '工单已成功提交到上游，工单号：'.$remoteId,
            ]);
            $firstReplyId = (int) ($ticket->replies()->oldest('id')->value('id') ?? 0);
            $ticket->replies()
                ->where('id', '>', $firstReplyId)
                ->orderBy('id')
                ->get()
                ->each(function (TicketReply $reply) use ($ticket, $binding): void {
                    try {
                        if ((int) $reply->is_staff === 1) {
                            $this->queueStaffReply($reply);
                        } else {
                            $this->queueClientReply($reply);
                        }
                    } catch (\Throwable $exception) {
                        $this->recordLog($ticket, [
                            'ticket_reply_id' => $reply->id,
                            'binding_id' => $binding->id,
                            'operation' => 'ticket.reply',
                            'event' => 'failed',
                            'status' => 'failed',
                            'reason_code' => 'history_reply_queue_failed',
                            'provider_key' => $binding->provider_key,
                            'supplier_id' => $binding->supplier_id,
                            'message' => mb_substr($exception->getMessage(), 0, 2000),
                        ]);
                    }
                });
        });
    }

    public function deliverReply(int $replyId): void
    {
        $reply = TicketReply::query()->with(['ticket.service', 'ticket.upstreamBinding', 'delivery'])->find($replyId);
        if (! $reply instanceof TicketReply || ! $reply->ticket instanceof Ticket) {
            return;
        }

        $ticket = $reply->ticket;
        $binding = $ticket->upstreamBinding;
        if (! $binding instanceof TicketUpstreamBinding) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'binding_missing',
                'message' => '工单没有上游绑定，回复未提交',
            ]);

            return;
        }
        if (! $binding->upstream_ticket_id) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'binding_id' => $binding->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'upstream_ticket_missing',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '上游工单尚未创建，回复未提交',
            ]);

            return;
        }
        if ($binding->provider_key !== ProviderKey::ZJMF_FINANCE_API) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'binding_id' => $binding->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'provider_mismatch',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => '工单绑定的上游接口不支持回复同步',
            ]);

            return;
        }
        $decision = $this->resolveContextDecision($ticket, requireRule: false);
        if ($decision['context'] === null) {
            $this->recordLog($ticket, [
                'ticket_reply_id' => $reply->id,
                'binding_id' => $binding->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => $decision['reason_code'],
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'message' => $decision['message'],
            ]);

            return;
        }
        $delivery = $reply->delivery;
        if (! $delivery instanceof TicketReplyDelivery) {
            $this->recordLog($reply->ticket, [
                'ticket_reply_id' => $reply->id,
                'operation' => 'ticket.reply',
                'event' => 'skipped',
                'status' => 'skipped',
                'reason_code' => 'delivery_record_missing',
                'message' => '工单回复没有对应的上游投递记录',
            ]);

            return;
        }
        if ($delivery->status === 'delivered') {
            return;
        }
        $claimed = TicketReplyDelivery::query()
            ->whereKey($delivery->id)
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('last_attempt_at', '<', now()->subMinutes(10)));
            })
            ->update(['status' => 'sending', 'last_attempt_at' => now()]);
        if ($claimed !== 1) {
            $this->recordLog($reply->ticket, [
                'ticket_reply_id' => $reply->id,
                'delivery_id' => $delivery->id,
                'binding_id' => $reply->ticket->upstreamBinding->id,
                'operation' => 'ticket.reply',
                'event' => 'claim_skipped',
                'status' => (string) $delivery->status,
                'reason_code' => 'queue_claim_failed',
                'provider_key' => $reply->ticket->upstreamBinding->provider_key,
                'supplier_id' => $reply->ticket->upstreamBinding->supplier_id,
                'attempt' => (int) $delivery->attempts,
                'message' => '回复投递任务未能获取发送锁，可能已有任务正在处理',
            ]);

            return;
        }
        $delivery->refresh();
        $delivery->loadMissing('reply.ticket.upstreamBinding');

        $binding = $reply->ticket->upstreamBinding;
        $this->recordLog($reply->ticket, [
            'ticket_reply_id' => $reply->id,
            'delivery_id' => $delivery->id,
            'binding_id' => $binding->id,
            'operation' => 'ticket.reply',
            'event' => 'sending',
            'status' => 'sending',
            'provider_key' => $binding->provider_key,
            'supplier_id' => $binding->supplier_id,
            'attempt' => (int) $delivery->attempts + 1,
            'message' => '开始提交工单回复到上游',
        ]);
        $this->attemptReply($delivery, function () use ($reply, $binding, $delivery): void {
            $supplier = $this->supplierForBinding($reply->ticket);
            $attachments = $this->uploadAttachments($supplier, (array) $reply->attachments);
            $response = $this->zjmfTransport()->post($supplier, '/ticket/reply', [
                'tid' => $binding->upstream_ticket_id,
                'content' => (string) ($reply->delivery?->content_prefix ?? '').ltrim((string) $reply->content),
                'attachment' => $attachments,
                'is_api' => 1,
                'request_id' => (string) ($delivery->idempotency_key ?? 'ticket-reply:'.$reply->id),
            ]);
            throw_if($this->extractSuccess($response) === false, new BusinessException((string) ($response['msg'] ?? '上游回复同步失败'), 42200));
        });
    }

    /**
     * @return array{context: array<string, mixed>|null, reason_code: string, message: string, provider_key: ?string, supplier_id: ?int}
     */
    private function resolveContextDecision(Ticket $ticket, bool $requireRule = true): array
    {
        $ticket->loadMissing('service');
        $service = $ticket->service;
        if ($service === null) {
            return $this->decision('service_missing', '工单未关联服务');
        }

        $providerKey = $this->bindings->providerKeyForService($service);
        $upstreamServiceId = $this->bindings->upstreamServiceIdForService($service);
        $supplierId = $this->bindings->supplierIdForService($service);
        if ($providerKey !== ProviderKey::ZJMF_FINANCE_API) {
            return $this->decision('provider_mismatch', '关联服务未使用支持工单传递的上游接口', $providerKey, $supplierId);
        }
        if ($upstreamServiceId === null) {
            return $this->decision('upstream_service_missing', '关联服务未配置上游服务', $providerKey, $supplierId);
        }
        if ($supplierId === null) {
            return $this->decision('supplier_missing', '关联服务未配置上游供应商', $providerKey);
        }

        $upstreamProductId = $this->bindings->upstreamProductIdForService($service);
        if ($requireRule && $upstreamProductId === null) {
            return $this->decision('upstream_product_missing', '关联服务未配置上游产品', $providerKey, $supplierId);
        }

        $supplier = Supplier::query()->find($supplierId);
        $supplierBinding = $supplier === null ? [] : $this->bindings->supplierBindingProjection($supplier, true, ProviderKey::ZJMF_FINANCE_API);
        if ($supplier === null) {
            return $this->decision('supplier_missing', '上游供应商不存在', $providerKey, $supplierId);
        }
        if ((int) $supplier->status !== 1) {
            return $this->decision('supplier_disabled', '上游供应商未启用', $providerKey, $supplierId);
        }
        if ((int) ($supplierBinding['status'] ?? 0) !== 1) {
            return $this->decision('supplier_binding_disabled', '上游供应商接口绑定未启用', $providerKey, $supplierId);
        }
        if ((string) ($supplierBinding['provider_key'] ?? '') !== ProviderKey::ZJMF_FINANCE_API) {
            return $this->decision('supplier_binding_missing', '上游供应商未配置 ZJMF 财务接口绑定', $providerKey, $supplierId);
        }
        if (! $requireRule) {
            return [
                'context' => [
                    'provider_key' => $providerKey,
                    'supplier_id' => $supplierId,
                    'upstream_service_id' => $upstreamServiceId,
                    'upstream_product_id' => $upstreamProductId,
                ],
                'reason_code' => 'binding_ready',
                'message' => '上游工单绑定可用',
                'provider_key' => $providerKey,
                'supplier_id' => (int) $supplierId,
            ];
        }

        $productId = $this->bindings->productIdForService($service);
        $ruleQuery = TicketDeliveryRule::query()
            ->where('department', (string) $ticket->department)
            ->where('supplier_id', $supplierId)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('enabled', true);
        $selectedRule = $productId === null ? null : (clone $ruleQuery)
            ->where('product_scope_mode', 'selected')
            ->whereHas('products', fn ($products) => $products->whereKey($productId))
            ->orderBy('id')
            ->first();
        $rule = $selectedRule ?? $ruleQuery
            ->where('product_scope_mode', 'all')
            ->orderBy('id')
            ->first();
        if (! $rule instanceof TicketDeliveryRule) {
            return $this->decision('rule_not_matched', '没有匹配当前工单部门和产品的启用规则', $providerKey, $supplierId);
        }

        $firstReplyContent = (string) ($ticket->replies()->oldest('id')->value('content') ?? '');
        foreach ($rule->maskKeywordList() as $keyword) {
            if ($keyword !== '' && Str::contains((string) $ticket->subject.' '.$firstReplyContent, $keyword, true)) {
                return $this->decision('mask_keyword_matched', '工单标题或首条回复命中屏蔽关键词', $providerKey, $supplierId);
            }
        }

        return [
            'context' => [
                'provider_key' => $providerKey,
                'supplier_id' => $supplierId,
                'upstream_department_id' => (string) $rule->upstream_department_id,
                'upstream_service_id' => $upstreamServiceId,
                'upstream_product_id' => $upstreamProductId,
            ],
            'reason_code' => 'matched',
            'message' => '已匹配上游转发规则',
            'provider_key' => $providerKey,
            'supplier_id' => (int) $supplierId,
        ];
    }

    /**
     * @return array{context:null, reason_code:string, message:string, provider_key:?string, supplier_id:?int}
     */
    private function decision(string $reasonCode, string $message, ?string $providerKey = null, ?int $supplierId = null): array
    {
        return [
            'context' => null,
            'reason_code' => $reasonCode,
            'message' => $message,
            'provider_key' => $providerKey,
            'supplier_id' => $supplierId,
        ];
    }

    private function queueReply(TicketReply $reply, string $prefix): void
    {
        $delivery = TicketReplyDelivery::query()->firstOrCreate(
            ['ticket_reply_id' => $reply->id],
            [
                'direction' => self::DIRECTION_OUTBOUND,
                'content_prefix' => $prefix,
                'status' => 'pending',
                'idempotency_key' => 'ticket-reply:'.$reply->id,
            ]
        );
        if ($delivery->status === 'delivered') {
            return;
        }

        $this->recordLog($reply->ticket, [
            'ticket_reply_id' => $reply->id,
            'delivery_id' => $delivery->id,
            'binding_id' => $reply->ticket->upstreamBinding?->id,
            'operation' => 'ticket.reply',
            'event' => 'queued',
            'status' => 'pending',
            'provider_key' => $reply->ticket->upstreamBinding?->provider_key,
            'supplier_id' => $reply->ticket->upstreamBinding?->supplier_id,
            'message' => '工单回复已进入上游投递队列',
        ]);
        DeliverTicketReplyToUpstreamJob::dispatch((int) $reply->id)->afterCommit();
    }

    private function syncAdminReplies(TicketUpstreamBinding $binding, Ticket $ticket): bool
    {
        if ($binding->provider_key !== ProviderKey::ZJMF_FINANCE_API) {
            return false;
        }

        $ticket->loadMissing('service');
        $productId = $ticket->service === null ? null : $this->bindings->productIdForService($ticket->service);
        $base = TicketDeliveryRule::query()
            ->where('department', (string) $ticket->department)
            ->where('supplier_id', (int) $binding->supplier_id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('enabled', true);

        $selected = $productId === null ? null : (clone $base)
            ->where('product_scope_mode', 'selected')
            ->whereHas('products', fn ($products) => $products->whereKey($productId))
            ->orderBy('id')
            ->first();
        if ($selected instanceof TicketDeliveryRule) {
            return (bool) $selected->sync_admin_replies;
        }

        $all = (clone $base)
            ->where('product_scope_mode', 'all')
            ->orderBy('id')
            ->first();

        return $all instanceof TicketDeliveryRule && (bool) $all->sync_admin_replies;
    }

    private function attemptBinding(TicketUpstreamBinding $binding, callable $send, callable $success): void
    {
        try {
            $binding->increment('attempts');
            $binding->update(['last_attempt_at' => now(), 'last_error' => null]);
            $success($send());
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 2000);
            TicketUpstreamBinding::query()
                ->whereKey($binding->id)
                ->where('status', 'sending')
                ->update(['status' => 'failed', 'last_error' => $message]);
            $this->recordLog($binding->ticket, [
                'binding_id' => $binding->id,
                'operation' => 'ticket.create',
                'event' => 'failed',
                'status' => 'failed',
                'reason_code' => 'upstream_rejected',
                'provider_key' => $binding->provider_key,
                'supplier_id' => $binding->supplier_id,
                'attempt' => (int) $binding->attempts,
                'message' => $message,
            ]);
            throw $e;
        }
    }

    private function attemptReply(TicketReplyDelivery $delivery, callable $send): void
    {
        $ticket = $delivery->reply?->ticket;
        try {
            $delivery->increment('attempts');
            $delivery->update(['last_attempt_at' => now(), 'last_error' => null]);
            $send();
            $updated = TicketReplyDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', 'sending')
                ->update(['status' => 'delivered', 'delivered_at' => now()]);
            if ($updated !== 1) {
                if ($ticket instanceof Ticket) {
                    $this->recordLog($ticket, [
                        'ticket_reply_id' => $delivery->ticket_reply_id,
                        'delivery_id' => $delivery->id,
                        'operation' => 'ticket.reply',
                        'event' => 'late_success',
                        'status' => (string) ($delivery->fresh()?->status ?? 'unknown'),
                        'reason_code' => 'stale_claim_completed',
                        'message' => '回复投递返回成功，但本地记录已由其他任务处理',
                    ]);
                }

                return;
            }
            $delivery->refresh();
            if ($ticket instanceof Ticket) {
                $this->recordLog($ticket, [
                    'ticket_reply_id' => $delivery->ticket_reply_id,
                    'delivery_id' => $delivery->id,
                    'binding_id' => $ticket->upstreamBinding?->id,
                    'operation' => 'ticket.reply',
                    'event' => 'succeeded',
                    'status' => 'delivered',
                    'provider_key' => $ticket->upstreamBinding?->provider_key,
                    'supplier_id' => $ticket->upstreamBinding?->supplier_id,
                    'attempt' => (int) $delivery->attempts,
                    'message' => '工单回复已成功同步到上游',
                ]);
            }
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 2000);
            TicketReplyDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', 'sending')
                ->update(['status' => 'failed', 'last_error' => $message]);
            if ($ticket instanceof Ticket) {
                $this->recordLog($ticket, [
                    'ticket_reply_id' => $delivery->ticket_reply_id,
                    'delivery_id' => $delivery->id,
                    'binding_id' => $ticket->upstreamBinding?->id,
                    'operation' => 'ticket.reply',
                    'event' => 'failed',
                    'status' => 'failed',
                    'reason_code' => 'upstream_rejected',
                    'provider_key' => $ticket->upstreamBinding?->provider_key,
                    'supplier_id' => $ticket->upstreamBinding?->supplier_id,
                    'attempt' => (int) $delivery->attempts,
                    'message' => $message,
                ]);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function registerTicketCallbackRequest(Supplier $supplier, Service $service, TicketUpstreamBinding $binding): array
    {
        $transport = $this->zjmfTransport();
        if (! method_exists($transport, 'registerDownstreamCallback')) {
            throw new BusinessException('当前上游驱动不支持工单回调注册', 42200);
        }

        $upstreamProductId = $this->bindings->upstreamProductIdForService($service);
        throw_if($upstreamProductId === null, new BusinessException('关联服务未配置上游产品，无法注册回调', 42200));

        $response = $transport->registerDownstreamCallback(
            $supplier,
            (int) $binding->upstream_service_id,
            (int) $upstreamProductId,
            (int) $service->id,
            PublicUrl::api(),
            TicketUpstreamCallbackToken::forServiceId((int) $service->id),
        );
        throw_if($this->extractSuccess($response) === false, new BusinessException((string) ($response['msg'] ?? '注册上游回调失败'), 42200));

        return $response;
    }

    private function supplierForBinding(Ticket $ticket): Supplier
    {
        $supplier = $ticket->upstreamBinding?->supplier_id
            ? Supplier::query()->find((int) $ticket->upstreamBinding->supplier_id)
            : null;
        throw_if($supplier === null, new BusinessException('工单上游供应商未配置', 42200));

        $providerKey = trim((string) ($ticket->upstreamBinding?->provider_key ?? ''));
        $supplier = $this->bindings->supplierWithRuntimeCredentials(
            $supplier,
            true,
            $providerKey !== '' ? $providerKey : null
        );
        if ($providerKey !== '' && (string) $supplier->getAttribute('provider_key') !== $providerKey) {
            throw new BusinessException('工单供应商绑定与上游接口不一致', 42200);
        }

        return $supplier;
    }

    private function logsQuery(Ticket $ticket): \Illuminate\Database\Eloquent\Builder
    {
        return TicketUpstreamDeliveryLog::query()
            ->with('supplier:id,name')
            ->where('ticket_id', (int) $ticket->id);
    }

    /** @param array<string, mixed> $data */
    private function recordLog(?Ticket $ticket, array $data): void
    {
        if (! $ticket instanceof Ticket || ! Schema::hasTable('ticket_upstream_delivery_logs')) {
            return;
        }

        try {
            TicketUpstreamDeliveryLog::query()->create(array_merge([
                'ticket_id' => $ticket->id,
                'direction' => self::DIRECTION_OUTBOUND,
                'operation' => 'ticket.create',
                'event' => 'info',
                'status' => 'pending',
                'occurred_at' => now(),
            ], $data));
        } catch (\Throwable $e) {
            Log::warning('写入工单上游投递日志失败', [
                'ticket_id' => $ticket->id,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_configured' => '未配置',
            'pending' => '等待发送',
            'sending' => '发送中',
            'delivered' => '已转发',
            'failed' => '转发失败',
            'skipped' => '未转发',
            default => $status !== '' ? $status : '未转发',
        };
    }

    private function zjmfTransport(): object
    {
        return app(ZjmfFinanceTransport::class);
    }

    private function uploadAttachments(Supplier $supplier, array $attachments): array
    {
        $result = [];
        foreach ($attachments as $attachment) {
            $path = is_array($attachment) ? (string) ($attachment['path'] ?? '') : (string) $attachment;
            if ($path === '') {
                continue;
            }
            $remote = $this->zjmfTransport()->uploadTicketAttachment($supplier, $path);
            if ($remote !== null) {
                $result[] = $remote;
            }
        }

        return $result;
    }

    private function extractRemoteId(array $response): ?string
    {
        $id = data_get($response, 'data.tid') ?? data_get($response, 'data.id') ?? data_get($response, 'tid');

        return $id === null || trim((string) $id) === '' ? null : (string) $id;
    }

    private function extractSuccess(array $response): bool
    {
        return (int) ($response['status'] ?? $response['code'] ?? 0) === 200
            || (int) ($response['code'] ?? -1) === 0
            || (bool) ($response['success'] ?? false);
    }

    private function priorityLabel(int $priority): string
    {
        return match ($priority) {
            4 => 'high',
            3, 2 => 'medium',
            default => 'low',
        };
    }
}
