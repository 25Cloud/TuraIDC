<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\BillingCycle;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Models\AdminUser;
use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ProductCatalog\ProductFullPathResolver;
use App\Services\System\NotificationService;
use App\Support\AdminPermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AdminOrderNotificationService
{
    private const TASK_KEY = 'admin-order-notification';

    public function __construct(
        private NotificationService $notificationService,
        private ?ProductFullPathResolver $productFullPathResolver = null,
    ) {}

    public function notifyOrderCreatedAfterResponse(Order $order): void
    {
        $this->scheduleNotificationAfterResponse((int) $order->id, 'created');
    }

    public function notifyOrderPaidAfterResponse(Order $order): void
    {
        $this->scheduleNotificationAfterResponse((int) $order->id, 'paid');
    }

    public function notifyInvoicePaidAfterResponse(Invoice $invoice): void
    {
        $invoiceId = (int) $invoice->id;
        if ($invoiceId <= 0) {
            return;
        }

        if (app()->runningInConsole()) {
            $this->dispatchInvoiceNotificationNow($invoiceId);

            return;
        }

        app()->terminating(function () use ($invoiceId): void {
            $this->dispatchInvoiceNotificationNow($invoiceId);
        });
    }

    public function notifyOrderCreated(Order $order): void
    {
        $invoiceColumns = ['id', 'order_id', 'invoice_no', 'amount', 'status', 'product_spec_snapshot', 'config_snapshot'];

        $order->loadMissing([
            'user:id,email,nickname',
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup:id,second_product_group_id,name',
            'product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
            'invoice:'.implode(',', $invoiceColumns),
        ]);

        $invoice = $order->invoice;
        if ($invoice instanceof Invoice) {
            $this->notifyInvoiceCreated($invoice, $order);

            return;
        }

        $this->sendToAdmins(
            $order,
            NotificationService::TEMPLATE_ADMIN_ORDER_CREATED,
            'order_created',
            function (AdminUser $admin) use ($order): array {
                return [
                    'site_name' => $this->siteName(),
                    'recipient_name' => $admin->display_name,
                    'user_name' => $order->user?->display_name ?: '客户',
                    'user_email' => (string) ($order->user?->email ?? '未绑定'),
                    'order_no' => (string) $order->order_no,
                    'invoice_no' => (string) ($order->invoice?->invoice_no ?? ''),
                    'product_name' => $this->resolveOrderProductDisplayText($order),
                    'billing_cycle_label' => $this->resolveBillingCycleLabel((string) $order->billing_cycle),
                    'order_amount' => number_format((float) ($order->amount ?? 0), 2, '.', ''),
                    'order_type_label' => $this->resolveOrderTypeLabel((string) $order->type),
                    'order_status_label' => OrderStatus::$labels[(int) ($order->status ?? OrderStatus::PENDING)] ?? '未知状态',
                    'created_at' => $order->created_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                ];
            }
        );
    }

    public function notifyOrderPaid(Order $order): void
    {
        $order->loadMissing([
            'user:id,email,nickname',
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup:id,second_product_group_id,name',
            'product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
            'invoice:id,order_id,invoice_no,amount,status,paid_at',
        ]);

        $payment = $this->resolveLatestSuccessfulPayment($order);

        $this->sendToAdmins(
            $order,
            NotificationService::TEMPLATE_ADMIN_ORDER_PAID,
            'order_paid',
            function (AdminUser $admin) use ($order, $payment): array {
                return [
                    'site_name' => $this->siteName(),
                    'recipient_name' => $admin->display_name,
                    'user_name' => $order->user?->display_name ?: '客户',
                    'user_email' => (string) ($order->user?->email ?? '未绑定'),
                    'order_no' => (string) $order->order_no,
                    'invoice_no' => (string) ($order->invoice?->invoice_no ?? ''),
                    'product_name' => $this->resolveOrderProductDisplayText($order),
                    'billing_cycle_label' => $this->resolveBillingCycleLabel((string) $order->billing_cycle),
                    'paid_amount' => number_format((float) ($order->paid_amount ?? $order->amount ?? 0), 2, '.', ''),
                    'payment_method' => $this->resolvePaymentGatewayLabel($payment?->gatewayKey() ?? ''),
                    'trade_no' => (string) ($payment?->trade_no ?? ''),
                    'paid_at' => $order->paid_at?->format('Y-m-d H:i:s')
                        ?? $order->invoice?->paid_at?->format('Y-m-d H:i:s')
                        ?? $payment?->paid_at?->format('Y-m-d H:i:s')
                        ?? now()->format('Y-m-d H:i:s'),
                ];
            }
        );
    }

    /**
     * 向全部管理员收件人发送同一封业务通知邮件。
     *
     * 幂等由 AutomationLog 台账保证：按 (任务, 动作, order, 收件人) 判重，
     * 已发成功的收件人在重试时会被跳过。
     *
     * 失败语义：单个收件人失败只记日志并继续（个别地址问题不该阻断其他人）；
     * 本轮全部收件人都失败时额外打一条 error 级日志，把「通道整体不可用」
     * 与「个别地址失败」区分开，便于告警。不外抛：本服务不经队列执行，
     * 外抛换不到重试，反而会把异常带进 terminating 回调或调用方流程。
     *
     * @param  callable(mixed):array<string, mixed>  $payloadBuilder  按收件人构造模板变量
     */
    private function sendToAdmins(Order $order, string $templateCode, string $action, callable $payloadBuilder): void
    {
        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        // 逐个收件人失败此前只记 Log::warning 且不外抛，队列任务因此拿到正常返回、
        // 标记成功，$tries 一次都用不上；又因失败时没调 markExecuted，也没有任何
        // 后续调度会重投——付款到账的管理员通知会在 SMTP 不可用时静默全丢。
        // 这里统计成败：部分失败不重试（已成功的由 AutomationLog 幂等台账挡住重发），
        // 全部失败则外抛，交给队列按 $backoff 重试。
        $attempted = 0;
        $failed = 0;

        foreach ($recipients as $admin) {
            $ruleKey = 'admin:'.(int) $admin->id;

            if (AutomationLog::hasRecord(self::TASK_KEY, $action, 'order', (int) $order->id, $ruleKey)) {
                continue;
            }

            $attempted++;

            try {
                $this->notificationService->sendTemplateEmail(
                    (string) $admin->email,
                    $templateCode,
                    $payloadBuilder($admin)
                );

                AutomationLog::markExecuted(
                    self::TASK_KEY,
                    $action,
                    'order',
                    (int) $order->id,
                    $ruleKey,
                    [
                        'admin_id' => (int) $admin->id,
                        'email' => (string) $admin->email,
                        'template_code' => $templateCode,
                    ]
                );
            } catch (\Throwable $exception) {
                $failed++;

                Log::warning('[管理员账单通知] 邮件发送失败', [
                    'action' => $action,
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'admin_id' => $admin->id,
                    'email' => (string) $admin->email,
                    'template_code' => $templateCode,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        // 本轮全部收件人都失败：说明是发信通道整体不可用（而非个别地址问题）。
        // 这里刻意只升级日志级别、不外抛——本服务不经队列任务执行：
        // scheduleNotificationAfterResponse() 走 app()->terminating() 回调，
        // console 场景下更是直接同步调用，整条链上没有任何 try/catch。
        // 外抛既换不到重试（没有 worker 承接），还会把异常带进 terminating 回调、
        // 甚至让调用方（如支付履约流程）连带失败。
        // 要真正获得重试，需把通知改造成 ShouldQueue 任务并配置 $tries/$backoff，
        // 那是独立的改动，不在本次范围。
        if ($attempted > 0 && $failed === $attempted) {
            Log::error('[管理员账单通知] 全部收件人发送失败，本次通知已丢失且不会自动重投', [
                'action' => $action,
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'template_code' => $templateCode,
                'attempted' => $attempted,
                'failed' => $failed,
            ]);
        }
    }

    private function notifyInvoiceCreated(Invoice $invoice, Order $order): void
    {
        $invoice->loadMissing([
            'user:id,email,nickname',
        ]);

        $snapshot = is_array($invoice->product_snapshot_json ?? null) ? $invoice->product_snapshot_json : [];
        $orderNo = trim((string) ($snapshot['order_no'] ?? $order->order_no ?? $invoice->invoice_no ?? ''));
        $productName = $this->resolveInvoiceProductDisplayText($invoice, $order);

        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $admin) {
            $ruleKey = 'admin:'.(int) $admin->id;

            if (AutomationLog::hasRecord(self::TASK_KEY, 'invoice_created', 'invoice', (int) $invoice->id, $ruleKey)) {
                continue;
            }

            try {
                $this->notificationService->sendTemplateEmail(
                    (string) $admin->email,
                    NotificationService::TEMPLATE_ADMIN_ORDER_CREATED,
                    [
                        'site_name' => $this->siteName(),
                        'recipient_name' => $admin->display_name,
                        'user_name' => $invoice->user?->display_name ?: $order->user?->display_name ?: '客户',
                        'user_email' => (string) ($invoice->user?->email ?? $order->user?->email ?? '未绑定'),
                        'order_no' => $orderNo,
                        'invoice_no' => (string) ($invoice->invoice_no ?? ''),
                        'product_name' => $productName,
                        'billing_cycle_label' => $this->resolveBillingCycleLabel((string) ($invoice->billing_cycle ?: $order->billing_cycle)),
                        'order_amount' => number_format((float) ($invoice->amount ?? $order->amount ?? 0), 2, '.', ''),
                        'order_type_label' => $this->resolveOrderTypeLabel((string) ($order->type ?? $invoice->type ?? '')),
                        'order_status_label' => InvoiceStatus::$labels[(int) ($invoice->status ?? InvoiceStatus::UNPAID)] ?? '未知状态',
                        'created_at' => $invoice->created_at?->format('Y-m-d H:i:s')
                            ?? $order->created_at?->format('Y-m-d H:i:s')
                            ?? now()->format('Y-m-d H:i:s'),
                    ]
                );

                AutomationLog::markExecuted(
                    self::TASK_KEY,
                    'invoice_created',
                    'invoice',
                    (int) $invoice->id,
                    $ruleKey,
                    [
                        'admin_id' => (int) $admin->id,
                        'email' => (string) $admin->email,
                        'template_code' => NotificationService::TEMPLATE_ADMIN_ORDER_CREATED,
                    ]
                );
            } catch (\Throwable $exception) {
                Log::warning('[管理员账单通知] 邮件发送失败', [
                    'action' => 'invoice_created',
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'admin_id' => $admin->id,
                    'email' => (string) $admin->email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function dispatchInvoiceNotificationNow(int $invoiceId): void
    {
        $invoice = Invoice::query()->with([
            'user:id,email,nickname',
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle,product_spec_snapshot,product_type_snapshot,config_snapshot',
            'order.product:id,product_type,service_type_code,product_group_id,remark,config_options,purchase_requires',
            'order.product.productGroup:id,second_product_group_id,name',
            'order.product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'order.product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup:id,second_product_group_id,name',
            'product.productGroup.secondProductGroup:id,first_product_group_id,name',
            'product.productGroup.secondProductGroup.firstProductGroup:id,code,name',
        ])->find($invoiceId);

        if (! $invoice instanceof Invoice || (int) $invoice->status !== InvoiceStatus::PAID) {
            return;
        }

        $payment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->where('status', 1)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first(Payment::gatewayProjectionColumns([
                'id',
                'invoice_id',
                'plugin_id',
                'trade_no',
                'paid_at',
            ]));

        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $admin) {
            $ruleKey = 'admin:'.(int) $admin->id;

            if (AutomationLog::hasRecord(self::TASK_KEY, 'invoice_paid', 'invoice', (int) $invoice->id, $ruleKey)) {
                continue;
            }

            try {
                $this->notificationService->sendTemplateEmail(
                    (string) $admin->email,
                    NotificationService::TEMPLATE_ADMIN_ORDER_PAID,
                    [
                        'site_name' => $this->siteName(),
                        'recipient_name' => $admin->display_name,
                        'user_name' => $invoice->user?->display_name ?: '客户',
                        'user_email' => (string) ($invoice->user?->email ?? '未绑定'),
                        'order_no' => (string) ($invoice->invoice_no ?? ''),
                        'invoice_no' => (string) ($invoice->invoice_no ?? ''),
                        'product_name' => $this->resolveInvoiceProductDisplayText($invoice, $invoice->order),
                        'billing_cycle_label' => $this->resolveBillingCycleLabel((string) ($invoice->billing_cycle ?? '')),
                        'paid_amount' => number_format((float) ($invoice->paid_amount ?? $invoice->amount ?? 0), 2, '.', ''),
                        'payment_method' => $this->resolvePaymentGatewayLabel($payment?->gatewayKey() ?? ''),
                        'trade_no' => (string) ($payment?->trade_no ?? ''),
                        'paid_at' => $invoice->paid_at?->format('Y-m-d H:i:s')
                            ?? $payment?->paid_at?->format('Y-m-d H:i:s')
                            ?? now()->format('Y-m-d H:i:s'),
                    ]
                );

                AutomationLog::markExecuted(
                    self::TASK_KEY,
                    'invoice_paid',
                    'invoice',
                    (int) $invoice->id,
                    $ruleKey,
                    [
                        'admin_id' => (int) $admin->id,
                        'email' => (string) $admin->email,
                        'template_code' => NotificationService::TEMPLATE_ADMIN_ORDER_PAID,
                    ]
                );
            } catch (\Throwable $exception) {
                Log::warning('[管理员账单通知] 邮件发送失败', [
                    'action' => 'invoice_paid',
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'admin_id' => $admin->id,
                    'email' => (string) $admin->email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function resolveRecipients(): Collection
    {
        return AdminUser::query()
            ->with('role')
            ->where('status', 1)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get(['id', 'username', 'nickname', 'email', 'role_id'])
            ->filter(function (AdminUser $admin) {
                $email = trim((string) ($admin->email ?? ''));

                return $email !== ''
                    && (
                        $admin->hasPermission(AdminPermissions::ORDER_LIST)
                        || $admin->hasPermission(AdminPermissions::ORDER_DETAIL)
                        || $admin->hasPermission(AdminPermissions::ORDER_MANAGE)
                        || $admin->hasPermission(AdminPermissions::INVOICE_LIST)
                        || $admin->hasPermission(AdminPermissions::INVOICE_DETAIL)
                        || $admin->hasPermission(AdminPermissions::INVOICE_MANAGE)
                    );
            })
            ->unique(fn (AdminUser $admin) => mb_strtolower(trim((string) $admin->email)))
            ->values();
    }

    private function resolveLatestSuccessfulPayment(Order $order): ?Payment
    {
        return Payment::query()
            ->where(function ($query) use ($order) {
                $query->where('order_id', $order->id);

                $invoiceId = (int) ($order->invoice?->id ?? 0);
                if ($invoiceId > 0) {
                    $query->orWhere('invoice_id', $invoiceId);
                }
            })
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->where('status', 1)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first(Payment::gatewayProjectionColumns([
                'id',
                'order_id',
                'invoice_id',
                'plugin_id',
                'trade_no',
                'paid_at',
            ]));
    }

    private function scheduleNotificationAfterResponse(int $orderId, string $event): void
    {
        if ($orderId <= 0) {
            return;
        }

        if (app()->runningInConsole()) {
            $this->dispatchNotificationNow($orderId, $event);

            return;
        }

        app()->terminating(function () use ($orderId, $event): void {
            $this->dispatchNotificationNow($orderId, $event);
        });
    }

    private function dispatchNotificationNow(int $orderId, string $event): void
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return;
        }

        match ($event) {
            'created' => $this->notifyOrderCreated($order),
            'paid' => $this->notifyOrderPaid($order),
            default => null,
        };
    }

    private function resolveBillingCycleLabel(string $cycle): string
    {
        return BillingCycle::label($cycle, $cycle) ?: '-';
    }

    private function resolveOrderTypeLabel(string $type): string
    {
        return [
            'new' => '新购账单',
            'renew' => '续费账单',
            'upgrade' => '升级账单',
        ][$type] ?? ($type !== '' ? $type : '账单');
    }

    private function resolvePaymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            'alipay' => '支付宝',
            'yipay' => '易支付',
            'balance' => '余额支付',
            'manual' => '手动入账',
            'wechat' => '微信支付',
            'bank_transfer' => '银行转账',
            default => $gateway !== '' ? $gateway : '未知方式',
        };
    }

    private function siteName(): string
    {
        return (string) config('idc.site_name', config('app.name', '图拉云'));
    }

    private function resolveOrderProductDisplayText(Order $order): string
    {
        $path = $this->productFullPathResolver()->pathForOrder($order);

        return $path !== '' ? $path : (string) $order->display_product_name;
    }

    private function resolveInvoiceProductDisplayText(Invoice $invoice, ?Order $order = null): string
    {
        $snapshot = is_array($invoice->product_snapshot_json ?? null) ? $invoice->product_snapshot_json : [];
        $snapshotPath = $this->productFullPathResolver()->pathFromSnapshot($snapshot);
        if ($snapshotPath !== '') {
            return $snapshotPath;
        }

        foreach (['product_name', 'product_spec_snapshot'] as $key) {
            $value = trim((string) ($snapshot[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $path = $this->productFullPathResolver()->pathForInvoice($invoice);
        if ($path !== '') {
            return $path;
        }

        if ($order instanceof Order) {
            return $this->resolveOrderProductDisplayText($order);
        }

        return (string) $invoice->display_product_name;
    }

    private function productFullPathResolver(): ProductFullPathResolver
    {
        return $this->productFullPathResolver ??= app(ProductFullPathResolver::class);
    }
}
