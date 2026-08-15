<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RechargeRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 账户流水来源回填命令
 *
 * 专家团 H1：account_transactions 大量流水 source_type 为空，无法从余额变动
 * 追溯业务来源。本命令对存量流水做**高置信**回填：
 *
 * 1. source_type 回填：对 source_type 为空但 source_id 在 invoices 表存在的行，
 *    统一回填 source_type='invoice'（经实库核实：529 行无来源类型中有 source_id
 *    的 512 行 source_id 全部能在 invoices 找到；早期充值无 payments/orders 实体）。
 * 2. recharge_records 桥接：对"已支付 recharge 发票 + 存在真实 payment +
 *    recharge_records 无记录"的充值补建桥接记录（payment_id 关联防重）。
 *
 * 默认 dry-run，追加 --apply 实际写入；两段逻辑均支持独立关闭。
 *
 * 用法：
 *   php artisan finance:backfill-ledger-sources
 *   php artisan finance:backfill-ledger-sources --apply --with-bridge
 */
class BackfillLedgerSourcesCommand extends Command
{
    protected $signature = 'finance:backfill-ledger-sources
        {--apply : 真正写入；不指定时仅 dry-run 预览}
        {--with-source : 执行 source_type 回填（默认仅当 --apply 时隐含执行）}
        {--with-bridge : 执行 recharge_records 桥接回填}
        {--limit=500 : 单批 source_type 回填条数}';

    protected $description = '高置信回填账户流水 source_type 与 recharge_records 充值桥接';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $doSource = $apply || (bool) $this->option('with-source');
        $doBridge = (bool) $this->option('with-bridge') || (bool) $this->option('apply');

        $this->line('[流水来源回填] apply='.($apply ? 'true' : 'false'));

        $sourceResult = ['candidates' => 0, 'processed' => 0, 'failed' => 0];
        $bridgeResult = ['candidates' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0];

        if ($doSource) {
            $sourceResult = $this->backfillSourceType($apply, $limit);
        }

        if ($doBridge) {
            $bridgeResult = $this->backfillRechargeBridge($apply);
        }

        $this->newLine();
        $this->line('[source_type] 待回填='.$sourceResult['candidates']
            .' 已处理='.$sourceResult['processed']
            .' 失败='.$sourceResult['failed']);
        $this->line('[recharge 桥接] 待回填='.$bridgeResult['candidates']
            .' 已处理='.$bridgeResult['processed']
            .' 跳过='.$bridgeResult['skipped']
            .' 失败='.$bridgeResult['failed']);

        return ($sourceResult['failed'] + $bridgeResult['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{candidates: int, processed: int, failed: int}
     */
    private function backfillSourceType(bool $apply, int $limit): array
    {
        $candidates = DB::table('account_transactions as at')
            ->join('invoices', 'invoices.id', '=', 'at.source_id')
            ->whereNull('at.source_type')
            ->whereNotNull('at.source_id')
            ->select('at.id', 'at.source_id')
            ->orderBy('at.id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $failed = 0;

        foreach ($candidates as $row) {
            if ($apply) {
                try {
                    DB::table('account_transactions')
                        ->where('id', (int) $row->id)
                        ->update(['source_type' => 'invoice']);
                    $processed++;
                } catch (\Throwable $exception) {
                    $this->error('    ✗ 流水 #'.$row->id.' 失败: '.$exception->getMessage());
                    $failed++;
                }
            }
        }

        if (! $apply) {
            $this->line('[source_type] 待回填 '.$candidates->count().' 行（dry-run）');
        }

        return ['candidates' => $candidates->count(), 'processed' => $processed, 'failed' => $failed];
    }

    /**
     * @return array{candidates: int, processed: int, skipped: int, failed: int}
     */
    private function backfillRechargeBridge(bool $apply): array
    {
        $candidates = Invoice::query()
            ->where('type', 'recharge')
            ->where('status', InvoiceStatus::PAID)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('recharge_records')
                    ->whereColumn('recharge_records.invoice_id', 'invoices.id');
            })
            ->orderBy('id')
            ->get();

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $invoice) {
            $payment = Payment::query()->where('invoice_id', (int) $invoice->id)->first();

            if (! $payment instanceof Payment) {
                $skipped++; // 早期充值无 payments 实体，无法做桥接，保留待人工

                continue;
            }

            $this->line(sprintf(
                '  → Invoice #%d -> Payment #%d 金额=%s（%s）',
                (int) $invoice->id,
                (int) $payment->id,
                (string) $invoice->amount,
                $apply ? '已写入' : '待写入',
            ));

            if (! $apply) {
                continue;
            }

            try {
                DB::transaction(function () use ($invoice, $payment): void {
                    $exists = RechargeRecord::query()
                        ->where('payment_id', (int) $payment->id)
                        ->exists();
                    if ($exists) {
                        return;
                    }

                    $transactionId = DB::table('account_transactions')
                        ->where('source_type', 'invoice')
                        ->where('source_id', (int) $invoice->id)
                        ->value('id');

                    RechargeRecord::query()->create([
                        'record_no' => RechargeRecord::generateRecordNo(),
                        'user_id' => (int) $invoice->user_id,
                        'order_id' => $invoice->order_id,
                        'invoice_id' => (int) $invoice->id,
                        'payment_id' => (int) $payment->id,
                        'account_transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                        'scene' => 'recharge',
                        'direction' => 'in',
                        'amount' => $this->positiveDecimal($payment->amount) ?? '0.00',
                        'currency' => 'CNY',
                        'entry_type' => 'third_party_payment',
                        'remark' => '历史充值桥接回填',
                        'trace_id' => $invoice->trace_id ?: $payment->trace_id,
                    ]);
                });
                $processed++;
            } catch (\Throwable $exception) {
                $this->error('    ✗ Invoice #'.$invoice->id.' 失败: '.$exception->getMessage());
                $failed++;
            }
        }

        return ['candidates' => $candidates->count(), 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function positiveDecimal(mixed $value): ?string
    {
        if ($value === null || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
