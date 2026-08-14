<?php

declare(strict_types=1);

namespace App\Services\Finance;

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

    private function sendToAdmins(Order $order, string $templateCode, string $action, callable $payloadBuilder): void
    {
        $recipients = $this->resolveRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $admin) {
            $ruleKey = 'admin:'.(int) $admin->id;

            if (AutomationLog::hasRecord(self::TASK_KEY, $action, 'order', (int) $order->id, $ruleKey)) {
                continue;
            }

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
        return [
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannually' => '半年付',
            'annually' => '年付',
            'biennially' => '两年付',
            'triennially' => '三年付',
            'one_time' => '一次性',
            'onetime' => '一次性',
        ][$cycle] ?? ($cycle !== '' ? $cycle : '-');
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
