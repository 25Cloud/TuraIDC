<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Finance\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackfillInvoiceItemProjectionsCommand extends Command
{
    protected $signature = 'finance:backfill-invoice-item-projections
        {--execute : 执行补齐；默认仅输出只读报告}
        {--invoice-ids= : 逗号分隔的账单 ID；不传时扫描全部账单}
        {--sample=20 : 只读报告展示的候选样本数}
        {--chunk=200 : 执行时每批处理的账单数}';

    protected $description = '为没有任何 invoice_items 的历史账单补齐明细投影';

    public function handle(InvoiceService $invoiceService): int
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_items')) {
            $this->error('invoices 或 invoice_items 表不存在，无法补齐账单明细投影');

            return self::FAILURE;
        }

        $invoiceIds = $this->selectedInvoiceIds();
        if ($invoiceIds === null) {
            $this->error('--invoice-ids 必须是逗号分隔的正整数账单 ID');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $sampleSize = max(0, (int) $this->option('sample'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $candidates = $this->candidateQuery($invoiceIds);
        $candidateCount = (clone $candidates)->count();
        $typeCounts = (clone $candidates)
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->orderBy('type')
            ->pluck('total', 'type');
        $samples = $sampleSize > 0
            ? (clone $candidates)
                ->orderBy('id')
                ->limit($sampleSize)
                ->get(['id', 'invoice_no', 'type', 'amount', 'created_at'])
            : collect();

        $this->info($execute ? '账单明细投影补齐（执行模式）' : '账单明细投影补齐（只读预检）');
        $this->line('筛选范围: '.($invoiceIds === [] ? '全部账单' : '账单 ID '.implode(',', $invoiceIds)));
        $this->line('限定条件: 仅处理不存在任何 invoice_items 的账单');
        $this->line('候选账单数: '.$candidateCount);

        if ($typeCounts->isNotEmpty()) {
            $this->line('候选账单类型分布:');
            foreach ($typeCounts as $type => $count) {
                $this->line('  - '.($type !== null && $type !== '' ? $type : '未分类').': '.(int) $count);
            }
        }

        if ($samples->isNotEmpty()) {
            $this->table(
                ['ID', '账单号', '类型', '金额', '创建时间'],
                $samples->map(static fn (Invoice $invoice): array => [
                    $invoice->id,
                    $invoice->invoice_no,
                    $invoice->type,
                    $invoice->amount,
                    $invoice->created_at?->format('Y-m-d H:i:s'),
                ])->all(),
            );
        }

        if (! $execute) {
            $this->warn('只读模式：未写入任何账单明细；添加 --execute 才会执行补齐。');

            return self::SUCCESS;
        }

        $attempted = 0;
        $backfilled = 0;
        $skipped = 0;
        $failed = 0;

        (clone $candidates)
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $invoices) use ($invoiceService, &$attempted, &$backfilled, &$skipped, &$failed): void {
                foreach ($invoices as $invoice) {
                    $invoiceId = (int) $invoice->id;
                    $attempted++;

                    try {
                        $outcome = DB::transaction(function () use ($invoiceId, $invoiceService): string {
                            $lockedInvoice = Invoice::query()
                                ->withTrashed()
                                ->lockForUpdate()
                                ->find($invoiceId);

                            if (! $lockedInvoice instanceof Invoice) {
                                return 'skipped';
                            }

                            $hasItems = DB::table('invoice_items')
                                ->where('invoice_id', $invoiceId)
                                ->lockForUpdate()
                                ->exists();

                            if ($hasItems) {
                                return 'skipped';
                            }

                            $syncedInvoice = $invoiceService->syncProjection($lockedInvoice);

                            return $syncedInvoice->items()->exists() ? 'backfilled' : 'failed';
                        });
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error("账单 #{$invoiceId} 补齐失败：{$exception->getMessage()}");

                        continue;
                    }

                    match ($outcome) {
                        'backfilled' => $backfilled++,
                        'skipped' => $skipped++,
                        default => $failed++,
                    };
                }
            });

        $remaining = $this->candidateQuery($invoiceIds)->count();

        $this->info('账单明细投影补齐完成');
        $this->line('初始候选: '.$candidateCount);
        $this->line('实际尝试: '.$attempted);
        $this->line('补齐成功: '.$backfilled);
        $this->line('跳过（执行期间已有明细或账单已不可用）: '.$skipped);
        $this->line('失败: '.$failed);
        $this->line('剩余候选: '.$remaining);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return Builder<Invoice>
     */
    private function candidateQuery(array $invoiceIds): Builder
    {
        return Invoice::query()
            ->withTrashed()
            ->when($invoiceIds !== [], static fn (Builder $query): Builder => $query->whereIn('id', $invoiceIds))
            ->whereDoesntHave('items');
    }

    /**
     * @return list<int>|null
     */
    private function selectedInvoiceIds(): ?array
    {
        $rawInvoiceIds = trim((string) $this->option('invoice-ids'));
        if ($rawInvoiceIds === '') {
            return [];
        }

        $invoiceIds = [];
        foreach (explode(',', $rawInvoiceIds) as $value) {
            $value = trim($value);
            if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
                return null;
            }

            $invoiceIds[] = (int) $value;
        }

        return array_values(array_unique($invoiceIds));
    }
}
