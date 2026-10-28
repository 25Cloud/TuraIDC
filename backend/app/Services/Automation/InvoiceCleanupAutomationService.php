<?php

namespace App\Services\Automation;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use App\Services\Order\OrderService;
use App\Services\Supplier\SupplierBalanceService;
use App\Services\System\SettingService;
use Illuminate\Support\Facades\Log;

/**
 * 账单清理自动化服务：定时把超时未支付的账单、订单、充值单关闭。
 * 替代旧的 OrderCleanupAutomationService。
 */
class InvoiceCleanupAutomationService
{
    public function __construct(
        private SettingService $settingService,
        private CheckoutService $checkoutService,
        private PaymentService $paymentService,
        private OrderService $orderService,
    ) {}

    public function handle(): array
    {
        $config = $this->settingService->getAutomationConfig();

        return [
            'invoices_cancelled' => $this->cleanupPendingInvoices($config),
            'orders_cancelled' => $this->cleanupPendingOrders($config),
            'recharges_expired' => $this->cleanupPendingRecharges($config),
            'supplier_balance_logs_purged' => $this->cleanupSupplierBalanceLogs($config),
        ];
    }

    /**
     * 管理端手动开通的账单：创建时 config_snapshot 打了 admin_manual 标记
     * （createDirect 直接写入，createFromOrder 从订单快照继承）。
     */
    private function isAdminManualInvoice(Invoice $invoice): bool
    {
        $snapshot = $invoice->config_snapshot;
        if (! is_array($snapshot)) {
            return false;
        }

        return filter_var($snapshot['admin_manual'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function cleanupPendingInvoices(array $config): int
    {
        $ttlSeconds = CheckoutSecurityService::paymentSessionTtlSeconds();
        $threshold = now()->subSeconds($ttlSeconds);

        $count = 0;

        // 两处收紧，与同文件 cleanupPendingOrders() 同口径：
        // 1) 续费账单在 SQL 层排除，不再「全部读进内存后 continue」。续费账单按 due_date
        //    计费、永远不会被本任务取消，所以它们会一直留在结果集里——这是本类唯一
        //    随业务单调增长且没有上界的加载，未付续费单一多就是一次内存尖峰。
        // 2) 改 chunkById 分批，积压时不会一次性把全部历史未付账单（含 product 关系）
        //    读进内存。
        // 管理员挂账账单仍走 PHP 判断（标记在 config_snapshot 里，且数量很少）。
        Invoice::query()
            ->with(['product'])
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE])
            ->where('type', '<>', InvoiceType::RENEW)
            ->where('created_at', '<=', $threshold)
            ->chunkById(200, function ($invoices) use (&$count, $ttlSeconds): void {
                foreach ($invoices as $invoice) {
                    // 管理员手动开通产生的挂账账单按 due_date 计费，不受 5 分钟支付会话窗口约束
                    if ($this->isAdminManualInvoice($invoice)) {
                        continue;
                    }

                    try {
                        $updated = $this->checkoutService->cancelExpiredUnpaidInvoice($invoice, [
                            'actor_type' => 'system',
                            'actor_name' => 'schedule:invoice-cleanup',
                            'reason' => 'payment_window_expired',
                        ]);

                        if ((int) $updated->status !== InvoiceStatus::CANCELLED) {
                            continue;
                        }

                        Log::info('[定时任务] 超时未支付账单自动关闭', [
                            'invoice_id' => $invoice->id,
                            'invoice_no' => $invoice->invoice_no,
                            'expire_after_seconds' => $ttlSeconds,
                            'age_minutes' => $invoice->created_at ? $invoice->created_at->diffInMinutes(now()) : null,
                        ]);
                        $count++;
                    } catch (\Throwable $exception) {
                        Log::warning('[定时任务] 账单自动关闭失败', [
                            'invoice_id' => $invoice->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    private function cleanupPendingOrders(array $config): int
    {
        // 续费账单已在 cleanupPendingInvoices 豁免 5 分钟支付会话取消，
        // 其影子续费订单必须同步豁免——OrderService::cancel 会级联取消关联账单，
        // 若只豁免账单，影子订单仍会在 5 分钟后被取消并把账单连带关闭，修复失效；
        // 且自动续费失败重试会复用「账单未付但订单已取消」的订单，导致扣款失败循环。
        // 逐单处理以收敛豁免逻辑，分批装载防积压场景一次性载入全表。
        $ttlSeconds = CheckoutSecurityService::paymentSessionTtlSeconds();
        $threshold = now()->subSeconds($ttlSeconds);

        $count = 0;

        Order::query()
            ->where('status', OrderStatus::PENDING)
            ->where('created_at', '<=', $threshold)
            ->chunkById(100, function ($orders) use (&$count): void {
                foreach ($orders as $order) {
                    // 系统生成的续费影子订单按 due_date 计费，与续费账单同口径豁免
                    if ((string) $order->type === OrderType::RENEW) {
                        continue;
                    }

                    // 管理员手动开通的挂账订单按 due_date 计费，且从未扣过库存，
                    // 被取消会触发 stock+1 造成库存虚增（与 OrderService 全量清理口径一致）
                    if ($this->isAdminManualOrder($order)) {
                        continue;
                    }

                    $updated = $this->orderService->cancelExpiredPendingOrder($order, [
                        'actor_type' => 'system',
                        'actor_name' => 'schedule:order-cleanup',
                        'reason' => 'payment_window_expired',
                    ]);

                    if ((int) $updated->status === OrderStatus::CANCELLED) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * 与 OrderService::isAdminManualOrder 同款实现：config_snapshot 为 array cast。
     */
    private function isAdminManualOrder(Order $order): bool
    {
        $snapshot = (array) $order->config_snapshot;

        return filter_var($snapshot['admin_manual'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 回收过期的上游额度变更流水。
     *
     * 挂在既有清理任务里而不是单开一个调度槽：流水量小、清理成本低，
     * 与待支付清理同频（每 15 分钟）已经绰绰有余。
     *
     * @param  array<string, mixed>  $config
     */
    private function cleanupSupplierBalanceLogs(array $config): int
    {
        $retentionDays = (int) ($config['supplier_balance_log_retention_days'] ?? 30);

        return app(SupplierBalanceService::class)->purgeExpiredLogs($retentionDays);
    }

    private function cleanupPendingRecharges(array $config): int
    {
        $ttlSeconds = CheckoutSecurityService::paymentSessionTtlSeconds();
        $threshold = now()->subSeconds($ttlSeconds);

        $updated = 0;

        // 与账单/订单清理同口径：分批装载，防积压场景一次性载入全表。
        Payment::query()
            ->whereNull('invoice_id')
            ->where('status', PaymentStatus::PENDING)
            ->where('created_at', '<=', $threshold)
            ->chunkById(100, function ($payments) use (&$updated): void {
                foreach ($payments as $payment) {
                    $closed = $this->paymentService->cancelExpiredPendingRecharge($payment, [
                        'actor_type' => 'system',
                        'actor_name' => 'schedule:invoice-cleanup',
                        'reason' => 'payment_window_expired',
                    ]);
                    if ((int) $closed->status === PaymentStatus::CANCELLED) {
                        $updated++;
                    }
                }
            });

        if ($updated > 0) {
            Log::info('[定时任务] 超时未支付充值单自动关闭', [
                'count' => $updated,
                'expire_after_seconds' => $ttlSeconds,
            ]);
        }

        return $updated;
    }
}
