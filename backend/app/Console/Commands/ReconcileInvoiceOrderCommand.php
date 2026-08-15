<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Finance\InvoiceOrderReconciliationService;
use Illuminate\Console\Command;

class ReconcileInvoiceOrderCommand extends Command
{
    protected $signature = 'trade:reconcile-invoice-order
        {--dry-run : 只审计影响范围，不写入数据库}
        {--execute : 执行修复；未指定时默认 dry-run}
        {--json : 以 JSON 输出结果}
        {--sample=20 : dry-run 输出的每类异常样本数量}
        {--snapshot-dir= : 执行修复前的快照输出目录}';

    protected $description = '审计并修复 orders 与 invoices 的绑定关系和支付状态投影';

    public function handle(InvoiceOrderReconciliationService $service): int
    {
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $sampleLimit = max(1, (int) $this->option('sample'));
        $snapshotDir = $this->option('snapshot-dir');

        $result = $execute
            ? $service->reconcile(is_string($snapshotDir) && $snapshotDir !== '' ? $snapshotDir : null)
            : $service->inspect($sampleLimit);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($execute) {
            $this->info('订单与账单关系修复完成');
            $this->line('- snapshot_path: '.$result['snapshot_path']);
            $this->printSummary('before', $result['before']);
            $this->printSummary('after', $result['after']);
            $this->line('- invoices_invalid_order_repaired: '.$result['changes']['invoices_invalid_order_repaired']);
            $this->line('- orders_without_invoice_repaired: '.$result['changes']['orders_without_invoice_repaired']);
            $this->line('- paid_order_invoice_status_mismatch_repaired: '.$result['changes']['paid_order_invoice_status_mismatch_repaired']);

            return self::SUCCESS;
        }

        $this->info('订单与账单关系审计预览');
        $this->printSummary('summary', $result['summary']);
        $this->warn('默认 dry-run，实际未写入。确认影响面后使用 --execute 执行，执行前会写入快照。');

        foreach ($result['samples'] as $name => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->line('');
            $this->line($name.' samples:');
            foreach (array_slice($rows, 0, 5) as $row) {
                $this->line('- '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function printSummary(string $label, array $summary): void
    {
        $this->line($label.':');
        $this->line('- invoices_invalid_order: '.$summary['invoices_invalid_order']);
        $this->line('- orders_without_invoice: '.$summary['orders_without_invoice']);
        $this->line('- paid_order_invoice_status_mismatch: '.$summary['paid_order_invoice_status_mismatch']);
        $this->line('- amount_mismatch: '.($summary['amount_mismatch'] ?? 0));
        $this->line('- completed_invoice_cancelled: '.($summary['completed_invoice_cancelled'] ?? 0));
    }
}
