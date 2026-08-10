<?php

declare(strict_types=1);

namespace App\Services\Client\V2;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\ContentArticle;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\Content\NoticeReadService;
use App\Services\Finance\CheckoutService;
use App\Services\Notification\UserNotificationService;
use App\Services\Order\OrderService;
use App\Services\Ticket\TicketService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ClientActionV2Service
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderService $orderService,
        private readonly ClientServiceConsoleService $clientServiceConsoleService,
        private readonly NoticeReadService $noticeReadService,
        private readonly UserNotificationService $userNotificationService,
        private readonly TicketService $ticketService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cancelInvoice(User $user, int $invoiceId, Request $request): array
    {
        $invoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->findOrFail($invoiceId);

        if ((int) $invoice->status !== InvoiceStatus::CANCELLED) {
            $invoice = $this->checkoutService->cancel($invoice, array_merge($this->operationContext($request), [
                'reason' => 'client_manual_cancel',
            ]));
        }

        return $this->result((int) $invoice->id, 'completed', '账单已取消', [
            'invoice_status' => (int) $invoice->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelOrder(User $user, int $orderId, Request $request): array
    {
        $order = Order::query()
            ->where('user_id', (int) $user->id)
            ->findOrFail($orderId);

        if ((int) $order->status !== OrderStatus::CANCELLED) {
            $this->orderService->cancel($order, [
                'actor_type' => 'client',
                'actor_user_id' => (int) $user->id,
                'ip_address' => $request->ip(),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ]);
            $order->refresh();
        }

        return $this->result((int) $order->id, 'completed', '订单已取消', [
            'order_status' => (int) $order->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function markNoticeRead(User $user, int $articleId): array
    {
        ContentArticle::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->published()
            ->findOrFail($articleId);

        $this->noticeReadService->markRead((int) $user->id, $articleId);

        return $this->result($articleId, 'completed', '公告已标记为已读');
    }

    /**
     * @return array<string, mixed>
     */
    public function markNotificationRead(User $user, int $notificationId): array
    {
        UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->findOrFail($notificationId);

        $this->userNotificationService->markRead((int) $user->id, $notificationId);

        return $this->result($notificationId, 'completed', '消息已标记为已读');
    }

    /**
     * @return array<string, mixed>
     */
    public function powerAction(User $user, int $serviceId, string $action, Request $request): array
    {
        $detail = $this->executeLockedServiceAction(
            $request,
            $serviceId,
            'power_'.$action,
            fn () => $this->clientServiceConsoleService->powerActionForUser(
                $user,
                $serviceId,
                $action,
                $this->operationContext($request)
            )
        );

        return $this->result($serviceId, 'queued', '操作已提交', [
            'detail' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(User $user, int $serviceId, array $data, Request $request): array
    {
        $detail = $this->executeLockedServiceAction(
            $request,
            $serviceId,
            'password_reset',
            fn () => $this->clientServiceConsoleService->resetPasswordForUser(
                $user,
                $serviceId,
                $data,
                $this->operationContext($request)
            )
        );

        return $this->result($serviceId, 'queued', '重置密码指令已提交', [
            'detail' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reinstall(User $user, int $serviceId, array $data, Request $request): array
    {
        $detail = $this->executeLockedServiceAction(
            $request,
            $serviceId,
            'reinstall',
            fn () => $this->clientServiceConsoleService->reinstallForUser(
                $user,
                $serviceId,
                $data,
                $this->operationContext($request)
            )
        );

        return $this->result($serviceId, 'queued', '重装系统任务已提交', [
            'detail' => $this->compactOperationDetail($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function recallTicketReply(User $user, int $ticketId, int $replyId): array
    {
        $ticket = Ticket::query()
            ->where('user_id', (int) $user->id)
            ->findOrFail($ticketId);

        $this->ticketService->recallReply($ticket, $replyId, (int) $user->id);

        return $this->result($replyId, 'completed', '消息已撤回', [
            'ticket_id' => $ticketId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(int $id, string $status, string $message, array $extra = []): array
    {
        $result = [
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ];

        if ($extra !== []) {
            $result['detail'] = $extra['detail'] ?? $extra;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationContext(Request $request, string $actorType = 'client'): array
    {
        $user = $request->user();

        return [
            'actor_type' => $actorType,
            'actor_user_id' => (int) ($user?->id ?? 0),
            'actor_name' => (string) ($user?->display_name ?? $user?->nickname ?? $user?->email ?? ''),
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
            'request_origin' => $this->resolveRequestOrigin($request),
        ];
    }

    private function resolveRequestOrigin(Request $request): string
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts)) {
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = (string) ($parts['host'] ?? '');
                $port = (int) ($parts['port'] ?? 0);

                if ($scheme !== '' && $host !== '') {
                    $defaultPort = $scheme === 'https' ? 443 : 80;

                    if ($port > 0 && $port !== $defaultPort) {
                        return sprintf('%s://%s:%d', $scheme, $host, $port);
                    }

                    return sprintf('%s://%s', $scheme, $host);
                }
            }
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function executeLockedServiceAction(Request $request, int $serviceId, string $action, callable $callback): mixed
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $lockKey = sprintf('lock:client:service:%d:%d:%s', $userId, $serviceId, sha1($action));

        try {
            return Cache::lock($lockKey, 20)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw new BusinessException('操作处理中，请勿重复提交', 40900, 409);
        }
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
            'second_verify_required' => array_key_exists('second_verify', $detail)
                ? $detail['second_verify'] !== []
                : null,
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
}
