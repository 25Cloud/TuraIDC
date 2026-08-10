<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 充值 Invoice 补偿命令
 *
 * 充值成功时，Payment.status 先变为 SUCCESS，随后异步创建 Invoice 并回填 invoice_id。
 * 若回调通知丢失或服务中途重启，Payment 会长期处于 status=SUCCESS 且 invoice_id=NULL 的悬空状态。
 * 本命令扫描这类记录并补建 Invoice，保障财务数据完整性。
 *
 * 建议注册为每小时执行一次：见 routes/console.php。
 */
class CompensateRechargeInvoicesCommand extends Command
{
    protected $signature = 'payment:compensate-recharge-invoices
        {--dry-run : 只扫描不写入，输出将处理的记录}
        {--limit=100 : 单次最多处理条数}
        {--grace-minutes=30 : 距创建时间超过多少分钟才纳入扫描（避免处理还在进行中的支付）}';

    protected $description = '扫描充值已成功但 invoice_id 为 NULL 的 Payment 记录，补建充值账单';

    public function handle(InvoiceService $invoiceService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $graceMinutes = max(5, (int) $this->option('grace-minutes'));

        $thirdPartyGateways = PaymentGatewayCode::thirdPartyGateways();

        $candidates = Payment::query()
            ->whereIn('gateway_key', $thirdPartyGateways)
            ->where('status', PaymentStatus::SUCCESS)
            ->whereNull('invoice_id')
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('[充值补偿] 无需处理的悬空记录');

            return self::SUCCESS;
        }

        $this->line("[充值补偿] 发现 {$candidates->count()} 条悬空记录（dry-run=".($isDryRun ? 'true' : 'false').')');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $payment) {
            $this->line("  → Payment #{$payment->id} payment_no={$payment->payment_no} amount={$payment->amount} gateway={$payment->gateway_key} created_at={$payment->created_at}");

            if ($isDryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($payment, $invoiceService) {
                    $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                    // 二次检查，防止并发下重复处理
                    if ($locked->invoice_id !== null) {
                        return;
                    }
                    if ((int) $locked->status !== PaymentStatus::SUCCESS) {
                        return;
                    }

                    $user = User::query()->findOrFail($locked->user_id);
                    $invoiceService->createForRecharge(
                        $user,
                        (float) $locked->amount,
                        $locked,
                        null,
                        (string) ($locked->trace_id ?? '')
                    );
                });

                $this->line('    ✓ 已补建 Invoice');
                Log::info('[充值补偿] 已补建充值 Invoice', [
                    'payment_id' => $payment->id,
                    'payment_no' => $payment->payment_no,
                    'amount' => $payment->amount,
                    'gateway_key' => $payment->gateway_key,
                ]);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("    ✗ 失败: {$e->getMessage()}");
                Log::error('[充值补偿] 补建充值 Invoice 失败', [
                    'payment_id' => $payment->id,
                    'payment_no' => $payment->payment_no,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $failed++;
            }
        }

        $this->line("[充值补偿] 完成：处理={$processed} 跳过={$skipped} 失败={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
