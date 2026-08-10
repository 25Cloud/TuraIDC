<?php

namespace App\Services\Automation;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use App\Services\Order\OrderService;
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
        ];
    }

    private function cleanupPendingInvoices(array $config): int
    {
        $ttlSeconds = CheckoutSecurityService::paymentSessionTtlSeconds();
        $threshold = now()->subSeconds($ttlSeconds);

        $invoices = Invoice::query()
            ->with(['product'])
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE])
            ->where('created_at', '<=', $threshold)
            ->get();

        $count = 0;

        foreach ($invoices as $invoice) {
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

        return $count;
    }

    private function cleanupPendingOrders(array $config): int
    {
        return $this->orderService->cancelExpiredPendingOrdersForUser(null, [
            'actor_type' => 'system',
            'actor_name' => 'schedule:order-cleanup',
            'reason' => 'payment_window_expired',
        ]);
    }

    private function cleanupPendingRecharges(array $config): int
    {
        $ttlSeconds = CheckoutSecurityService::paymentSessionTtlSeconds();
        $threshold = now()->subSeconds($ttlSeconds);

        $payments = Payment::query()
            ->whereNull('invoice_id')
            ->where('status', PaymentStatus::PENDING)
            ->where('created_at', '<=', $threshold)
            ->get();

        $updated = 0;

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

        if ($updated > 0) {
            Log::info('[定时任务] 超时未支付充值单自动关闭', [
                'count' => $updated,
                'expire_after_seconds' => $ttlSeconds,
            ]);
        }

        return $updated;
    }
}
