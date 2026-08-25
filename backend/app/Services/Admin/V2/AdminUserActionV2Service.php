<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Models\Invoice;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Http\Request;

class AdminUserActionV2Service
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(User $user, bool $enabled): array
    {
        $targetStatus = $enabled ? 1 : 0;

        if ((int) $user->status !== $targetStatus) {
            $user = $this->users->toggleStatus($user);
        } else {
            $user = $user->fresh() ?? $user;
        }

        return $this->result((int) $user->id, 'completed', '用户状态已更新', [
            'type' => 'status',
            'user' => [
                'id' => (int) $user->id,
                'status' => (int) $user->status,
                'enabled' => (int) $user->status === 1,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function servicePower(User $user, int $serviceId, string $action, Request $request): array
    {
        $detail = $this->users->servicePower($user, $serviceId, $action, $this->actorContext($request));

        return $this->result($serviceId, 'queued', '操作已提交', [
            'type' => 'power',
            'service_id' => $serviceId,
            'operation' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resetServicePassword(User $user, int $serviceId, array $payload, Request $request): array
    {
        $detail = $this->users->serviceResetPassword($user, $serviceId, $payload, $this->actorContext($request));

        return $this->result($serviceId, 'queued', '重置密码指令已提交', [
            'type' => 'password_reset',
            'service_id' => $serviceId,
            'operation' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundInvoice(User $user, int $invoiceId, array $payload, Request $request): array
    {
        $refundDetail = $this->users->refundInvoice($user, $invoiceId, $payload, $this->operatorContext($request));
        $invoice = Invoice::query()->find($invoiceId);
        $documentLinks = is_array($refundDetail['document_links'] ?? null)
            ? $refundDetail['document_links']
            : [];

        return $this->result($invoiceId, 'completed', '账单已完成退款', [
            'type' => 'invoice_refund',
            'invoice' => [
                'id' => $invoiceId,
                'status' => $invoice ? (int) $invoice->status : null,
                'refund_method' => $invoice?->refund_method,
                'refund_amount' => $this->formatNullableAmount($invoice?->refund_amount),
                'refunded_at' => $invoice?->refunded_at?->format('Y-m-d H:i:s'),
            ],
            'documents' => [
                'refund_id' => isset($documentLinks['refund_id']) ? (int) $documentLinks['refund_id'] : null,
                'refund_invoice_id' => isset($documentLinks['refund_invoice_id']) ? (int) $documentLinks['refund_invoice_id'] : null,
                'recharge_record_id' => isset($documentLinks['recharge_record_id']) ? (int) $documentLinks['recharge_record_id'] : null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refundService(User $user, int $serviceId, array $payload, Request $request): array
    {
        $detail = $this->users->refundService($user, $serviceId, $payload, $this->operatorContext($request));
        $refund = is_array($detail['refund'] ?? null) ? $detail['refund'] : [];

        return $this->result($serviceId, 'completed', '服务已完成退款', [
            'type' => 'service_refund',
            'service' => [
                'id' => $serviceId,
                'order_id' => isset($detail['order_id']) ? (int) $detail['order_id'] : null,
                'order_status' => isset($detail['order_status']) ? (int) $detail['order_status'] : null,
                'already_refunded' => (bool) ($detail['already_refunded'] ?? false),
            ],
            'refund' => $this->compactRefund($refund),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function suspendService(User $user, int $serviceId, array $payload, Request $request): array
    {
        $detail = $this->users->suspendService($user, $serviceId, $payload, $this->actorContext($request));

        return $this->result($serviceId, 'completed', '实例已暂停', [
            'type' => 'suspend',
            'service_id' => $serviceId,
            'operation' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function unsuspendService(User $user, int $serviceId, Request $request): array
    {
        $detail = $this->users->unsuspendService($user, $serviceId, $this->actorContext($request));

        return $this->result($serviceId, 'completed', '实例已解除暂停', [
            'type' => 'unsuspend',
            'service_id' => $serviceId,
            'operation' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reinstallService(User $user, int $serviceId, array $payload, Request $request): array
    {
        $detail = $this->users->serviceReinstall($user, $serviceId, $payload, $this->actorContext($request));

        return $this->result($serviceId, 'queued', '重装系统任务已提交', [
            'type' => 'reinstall',
            'service_id' => $serviceId,
            'operation' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reinstallServiceOptions(User $user, int $serviceId, bool $forceRefresh = false): array
    {
        $options = $this->users->serviceReinstallOptions($user, $serviceId, $forceRefresh);

        return [
            'service_id' => $serviceId,
            'os' => is_array($options['os'] ?? null) ? $options['os'] : [],
            'os_groups' => is_array($options['os_groups'] ?? null) ? $options['os_groups'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function renewServicePreview(User $user, int $serviceId, ?string $billingCycle = null): array
    {
        $preview = $this->users->renewServicePreview($user, $serviceId, $billingCycle);

        return [
            'service_id' => $serviceId,
            'preview' => is_array($preview) ? $preview : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function renewServiceOrder(User $user, int $serviceId, string $billingCycle, Request $request): array
    {
        $order = $this->users->renewServiceOrder($user, $serviceId, $billingCycle, $this->actorContext($request));

        return $this->result($serviceId, 'completed', '续费订单已创建', [
            'type' => 'renew',
            'service_id' => $serviceId,
            'order' => $order,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function result(int $id, string $status, string $message, array $detail): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'message' => $message,
            'detail' => $detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actorContext(Request $request): array
    {
        $operator = $request->user();

        return [
            'actor_type' => 'admin',
            'actor_user_id' => (int) ($operator?->id ?? 0),
            'actor_name' => (string) ($operator?->username ?? $operator?->name ?? $operator?->email ?? 'admin'),
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operatorContext(Request $request): array
    {
        $operator = $request->user();

        return [
            'operator_type' => 'admin',
            'operator_id' => (int) ($operator?->id ?? 0),
            'operator_name' => (string) ($operator?->username ?? $operator?->name ?? $operator?->email ?? 'admin'),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
            'ip_address' => (string) $request->ip(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactOperationDetail(mixed $detail): array
    {
        if (! is_array($detail)) {
            return [];
        }

        return array_filter([
            'action' => isset($detail['action']) ? (string) $detail['action'] : null,
            'action_label' => isset($detail['action_label']) ? (string) $detail['action_label'] : null,
            'message' => isset($detail['message']) ? (string) $detail['message'] : null,
            'status' => $this->compactStatus($detail['status'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compactStatus(mixed $status): ?array
    {
        if (! is_array($status)) {
            return null;
        }

        $allowed = [
            'status',
            'status_label',
            'message',
            'progress',
            'description',
            'code',
        ];

        $compact = array_intersect_key($status, array_fill_keys($allowed, true));

        return $compact === [] ? null : $compact;
    }

    /**
     * @param  array<string, mixed>  $refund
     * @return array<string, mixed>
     */
    private function compactRefund(array $refund): array
    {
        return array_filter([
            'refund_method' => isset($refund['refund_method']) ? (string) $refund['refund_method'] : null,
            'refund_amount' => isset($refund['refund_amount']) ? (string) $refund['refund_amount'] : null,
            'refund_reason' => isset($refund['refund_reason']) ? (string) $refund['refund_reason'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function formatNullableAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
