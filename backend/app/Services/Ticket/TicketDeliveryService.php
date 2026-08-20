<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Exceptions\BusinessException;
use App\Jobs\DeliverTicketReplyToUpstreamJob;
use App\Jobs\DeliverTicketToUpstreamJob;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketDeliveryRule;
use App\Models\TicketReply;
use App\Models\TicketReplyDelivery;
use App\Models\TicketUpstreamBinding;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\ProviderKey;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
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
            ->first(['ticket_delivery_enabled']);
        throw_if($binding === null, new BusinessException('供应商未配置启用的 ZJMF 财务接口绑定', 42200));

        return $this->zjmfTransport()->getTicketDepartments($supplier);
    }

    public function queueTicket(Ticket $ticket): void
    {
        $context = $this->resolveContext($ticket);
        if ($context === null) {
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
            return;
        }
        $binding->update(['status' => 'pending', 'last_error' => null]);

        DeliverTicketToUpstreamJob::dispatch((int) $ticket->id)->afterCommit();
    }

    public function queueClientReply(TicketReply $reply): void
    {
        $ticket = $reply->relationLoaded('ticket') ? $reply->ticket : $reply->load('ticket')->ticket;
        if (! $ticket instanceof Ticket || ! $ticket->upstreamBinding()->exists()) {
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
        if (! $binding instanceof TicketUpstreamBinding || ! $this->syncAdminReplies($binding, $ticket)) {
            return;
        }

        $this->queueReply($reply, '[下游管理员消息]');
    }

    public function deliverTicket(int $ticketId): void
    {
        $ticket = Ticket::query()->with(['service', 'upstreamBinding'])->find($ticketId);
        if (! $ticket instanceof Ticket) {
            return;
        }

        $binding = $ticket->upstreamBinding;
        if (! $binding instanceof TicketUpstreamBinding
            || $binding->provider_key !== ProviderKey::ZJMF_FINANCE_API
            || ! $this->supplierDeliveryEnabled((int) $binding->supplier_id)
            || $binding->upstream_ticket_id !== null
        ) {
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
            return;
        }
        $binding->refresh();

        $this->attemptBinding($binding, function () use ($ticket, $binding): string {
            $service = $ticket->service;
            throw_if($service === null, new BusinessException('工单关联服务不存在', 42200));

            $supplier = Supplier::query()->find($binding->supplier_id);
            throw_if($supplier === null, new BusinessException('工单上游供应商未配置', 42200));
            $supplier = $this->bindings->supplierWithRuntimeCredentials($supplier, true, ProviderKey::ZJMF_FINANCE_API);
            $token = TicketUpstreamCallbackToken::forServiceId((int) $service->id);
            $transport = $this->zjmfTransport();
            if (method_exists($transport, 'registerDownstreamCallback')) {
                $response = $transport->registerDownstreamCallback(
                    $supplier,
                    (int) $binding->upstream_service_id,
                    (int) ($this->bindings->upstreamProductIdForService($service) ?? 0),
                    (int) $service->id,
                    PublicUrl::api(),
                    $token
                );
                throw_if($this->extractSuccess($response) === false, new BusinessException((string) ($response['msg'] ?? '注册上游回调失败'), 42200));
            }

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
            ];

            $response = $this->zjmfTransport()->post($supplier, '/ticket/create', $payload);
            $upstreamId = $this->extractRemoteId($response);
            throw_if($upstreamId === null, new BusinessException((string) ($response['msg'] ?? '上游工单创建失败'), 42200));

            return $upstreamId;
        }, function (string $remoteId) use ($binding, $ticket): void {
            $binding->update(['upstream_ticket_id' => $remoteId, 'status' => 'delivered']);
            $firstReplyId = (int) ($ticket->replies()->oldest('id')->value('id') ?? 0);
            $ticket->replies()
                ->where('id', '>', $firstReplyId)
                ->orderBy('id')
                ->get()
                ->each(function (TicketReply $reply): void {
                    if ((int) $reply->is_staff === 1) {
                        $this->queueStaffReply($reply);
                    } else {
                        $this->queueClientReply($reply);
                    }
                });
        });
    }

    public function deliverReply(int $replyId): void
    {
        $reply = TicketReply::query()->with(['ticket.service', 'ticket.upstreamBinding', 'delivery'])->find($replyId);
        if (! $reply instanceof TicketReply
            || ! $reply->ticket?->upstreamBinding?->upstream_ticket_id
            || $reply->ticket->upstreamBinding->provider_key !== ProviderKey::ZJMF_FINANCE_API
        ) {
            return;
        }
        if (! $this->supplierDeliveryEnabled((int) $reply->ticket->upstreamBinding->supplier_id)) {
            return;
        }

        $delivery = $reply->delivery;
        if (! $delivery instanceof TicketReplyDelivery || $delivery->status === 'delivered') {
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
            return;
        }
        $delivery->refresh();

        $binding = $reply->ticket->upstreamBinding;
        $this->attemptReply($delivery, function () use ($reply, $binding): void {
            $supplier = $this->supplierForBinding($reply->ticket);
            $attachments = $this->uploadAttachments($supplier, (array) $reply->attachments);
            $response = $this->zjmfTransport()->post($supplier, '/ticket/reply', [
                'tid' => $binding->upstream_ticket_id,
                'content' => (string) ($reply->delivery?->content_prefix ?? '').ltrim((string) $reply->content),
                'attachment' => $attachments,
                'is_api' => 1,
            ]);
            throw_if($this->extractSuccess($response) === false, new BusinessException((string) ($response['msg'] ?? '上游回复同步失败'), 42200));
        });
    }

    /** @return array<string, mixed>|null */
    private function resolveContext(Ticket $ticket): ?array
    {
        $ticket->loadMissing('service');
        $service = $ticket->service;
        if ($service === null) {
            return null;
        }

        $providerKey = $this->bindings->providerKeyForService($service);
        $upstreamServiceId = $this->bindings->upstreamServiceIdForService($service);
        $supplierId = $this->bindings->supplierIdForService($service);
        if ($providerKey !== ProviderKey::ZJMF_FINANCE_API || $upstreamServiceId === null || $supplierId === null) {
            return null;
        }

        $supplier = Supplier::query()->find($supplierId);
        $supplierBinding = $supplier === null ? [] : $this->bindings->supplierBindingProjection($supplier, true, ProviderKey::ZJMF_FINANCE_API);
        if ($supplier === null
            || (int) $supplier->status !== 1
            || (int) ($supplierBinding['status'] ?? 0) !== 1
            || (string) ($supplierBinding['provider_key'] ?? '') !== ProviderKey::ZJMF_FINANCE_API
            || (bool) ($supplierBinding['ticket_delivery_enabled'] ?? false) !== true
        ) {
            return null;
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
            return null;
        }

        $firstReplyContent = (string) ($ticket->replies()->oldest('id')->value('content') ?? '');
        foreach ($rule->maskKeywordList() as $keyword) {
            if ($keyword !== '' && Str::contains((string) $ticket->subject.' '.$firstReplyContent, $keyword, true)) {
                return null;
            }
        }

        return [
            'provider_key' => $providerKey,
            'supplier_id' => $supplierId,
            'upstream_department_id' => (string) $rule->upstream_department_id,
            'upstream_service_id' => $upstreamServiceId,
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

        DeliverTicketReplyToUpstreamJob::dispatch((int) $reply->id)->afterCommit();
    }

    private function syncAdminReplies(TicketUpstreamBinding $binding, Ticket $ticket): bool
    {
        if ($binding->provider_key !== ProviderKey::ZJMF_FINANCE_API || ! $this->supplierDeliveryEnabled((int) $binding->supplier_id)) {
            return false;
        }

        $ticket->loadMissing('service');
        $productId = $ticket->service === null ? null : $this->bindings->productIdForService($ticket->service);
        $base = TicketDeliveryRule::query()
            ->where('department', (string) $ticket->department)
            ->where('supplier_id', (int) $binding->supplier_id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('enabled', true)
            ->where('sync_admin_replies', true);

        return ($productId !== null && (clone $base)->where('product_scope_mode', 'selected')
            ->whereHas('products', fn ($products) => $products->whereKey($productId))->exists())
            || $base->where('product_scope_mode', 'all')->exists();
    }

    private function supplierDeliveryEnabled(int $supplierId): bool
    {
        if ($supplierId <= 0) {
            return false;
        }

        $binding = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('status', 1)
            ->first(['ticket_delivery_enabled']);

        return $binding !== null && (bool) $binding->ticket_delivery_enabled;
    }

    private function attemptBinding(TicketUpstreamBinding $binding, callable $send, callable $success): void
    {
        try {
            $binding->increment('attempts');
            $binding->update(['last_attempt_at' => now(), 'last_error' => null]);
            $success($send());
        } catch (\Throwable $e) {
            $binding->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    private function attemptReply(TicketReplyDelivery $delivery, callable $send): void
    {
        try {
            $delivery->increment('attempts');
            $delivery->update(['last_attempt_at' => now(), 'last_error' => null]);
            $send();
            $delivery->update(['status' => 'delivered', 'delivered_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    private function supplierForBinding(Ticket $ticket): Supplier
    {
        $supplier = $ticket->upstreamBinding?->supplier_id
            ? Supplier::query()->find((int) $ticket->upstreamBinding->supplier_id)
            : null;
        throw_if($supplier === null, new BusinessException('工单上游供应商未配置', 42200));

        return $this->bindings->supplierWithRuntimeCredentials($supplier, true);
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
