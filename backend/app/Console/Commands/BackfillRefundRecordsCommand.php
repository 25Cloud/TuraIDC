<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 退款记录回填命令
 *
 * 历史退款场景：订单与支付已标记"已退款"，但作为对外财务实体的发票未落退款
 * 标记、refunds 表无记录（专家团 M2 发现）。本命令扫描"支付已退款但 refunds
 * 无对应记录"的支付，回填 refunds 行并同步发票退款标记，使退款闭环在账本上
 * 可审计、对账时不虚增已支付收入。
 *
 * 默认 dry-run，确认后追加 --apply 实际写入。单笔退款独立事务。
 *
 * 用法：
 *   php artisan finance:backfill-refund-records
 *   php artisan finance:backfill-refund-records --apply
 */
class BackfillRefundRecordsCommand extends Command
{
    protected $signature = 'finance:backfill-refund-records
        {--apply : 真正写入；不指定时仅 dry-run 预览}
        {--limit=100 : 单次最多处理条数}';

    protected $description = '回填已退款但未落 refunds 表的退款记录，并同步发票退款标记';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = Payment::query()
            ->where('status', PaymentStatus::REFUNDED)
            ->whereNotNull('invoice_id')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('refunds')
                    ->whereColumn('refunds.payment_id', 'payments.id');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('[退款回填] 无需处理的记录');

            return self::SUCCESS;
        }

        $this->line('[退款回填] 发现 '.$candidates->count().' 笔待回填（apply='.($apply ? 'true' : 'false').'）');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $payment) {
            $invoice = Invoice::query()->find($payment->invoice_id);

            if (! $invoice instanceof Invoice) {
                $this->line("  → Payment #{$payment->id} invoice_id={$payment->invoice_id} 发票不存在，跳过");
                $skipped++;

                continue;
            }

            $amount = $this->positiveDecimal($payment->amount ?? null)
                ?? $this->positiveDecimal($invoice->amount ?? null)
                ?? '0.00';

            $this->line(sprintf(
                '  → Payment #%d -> Invoice #%d 金额=%s 状态=%d（refund=%s）',
                (int) $payment->id,
                (int) $invoice->id,
                $amount,
                (int) $invoice->status,
                $apply ? '已写入' : '待写入',
            ));

            if (! $apply) {
                continue;
            }

            try {
                DB::transaction(function () use ($payment, $invoice, $amount): void {
                    // 并发/重复保护：二次检查
                    $exists = Refund::query()->where('payment_id', (int) $payment->id)->exists();
                    if ($exists) {
                        return;
                    }

                    Refund::query()->create([
                        'refund_no' => Refund::generateRefundNo(),
                        'payment_id' => (int) $payment->id,
                        'invoice_id' => (int) $invoice->id,
                        'user_id' => (int) $invoice->user_id,
                        'amount' => $amount,
                        'status' => Refund::STATUS_COMPLETED,
                        'refund_method' => 'balance',
                        'currency' => 'CNY',
                        'reason' => '历史退款回填（订单/支付已退款，发票未落账）',
                        'refunded_at' => $invoice->refunded_at ?: $payment->paid_at ?: now(),
                        'trace_id' => $invoice->trace_id ?: $payment->trace_id,
                    ]);

                    if ((int) $invoice->status !== InvoiceStatus::REFUNDED) {
                        DB::table('invoices')
                            ->where('id', (int) $invoice->id)
                            ->update([
                                'status' => InvoiceStatus::REFUNDED,
                                'refund_amount' => $amount,
                                'refunded_at' => $invoice->refunded_at ?: now(),
                                'refund_trace_id' => $invoice->trace_id ?: $payment->trace_id,
                                'updated_at' => now(),
                            ]);
                    }
                });
                $processed++;
            } catch (\Throwable $exception) {
                $this->error('    ✗ 失败: '.$exception->getMessage());
                $failed++;
            }
        }

        $this->line('[退款回填] 完成：处理='.$processed.' 跳过='.$skipped.' 失败='.$failed);

        if (! $apply && $processed === 0) {
            $this->newLine();
            $this->warn('以上为 dry-run 预览。确认无误后，追加 --apply 实际执行。');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function positiveDecimal(mixed $value): ?string
    {
        if ($value === null || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
