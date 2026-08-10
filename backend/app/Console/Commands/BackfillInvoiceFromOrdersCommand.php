<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillInvoiceFromOrdersCommand extends Command
{
    protected $signature = 'invoices:backfill-from-orders {--dry-run : 只预览不实际写入} {--chunk=200 : 每批处理数量}';

    protected $description = '将 orders 表的关键字段回填到关联的 invoices 记录中（订单合并账单迁移阶段1）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! Schema::hasTable('orders') || ! Schema::hasTable('invoices')) {
            $this->error('orders 或 invoices 表不存在');

            return 1;
        }

        if (! Schema::hasColumn('invoices', 'product_id')) {
            $this->error('invoices 表尚未添加新字段，请先运行 php artisan migrate');

            return 1;
        }

        $totalInvoices = DB::table('invoices')->whereNotNull('order_id')->where('order_id', '>', 0)->count();
        $this->info("待回填的账单数量：{$totalInvoices}");

        if ($totalInvoices === 0) {
            $this->info('无需回填');

            return 0;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] 预览模式，不会实际写入数据');
        }

        $processed = 0;
        $skipped = 0;
        $updated = 0;

        DB::table('invoices')
            ->whereNotNull('order_id')
            ->where('order_id', '>', 0)
            ->orderBy('id')
            ->chunk($chunkSize, function ($invoices) use ($dryRun, &$processed, &$skipped, &$updated) {
                $orderIds = $invoices->pluck('order_id')->unique()->filter()->values()->all();
                $orders = DB::table('orders')->whereIn('id', $orderIds)->get()->keyBy('id');

                foreach ($invoices as $invoice) {
                    $processed++;
                    $order = $orders->get($invoice->order_id);

                    if (! $order) {
                        $skipped++;

                        continue;
                    }

                    // 如果已经回填过（product_id 不为空），跳过
                    if ($invoice->product_id !== null && (int) $invoice->product_id > 0) {
                        $skipped++;

                        continue;
                    }

                    $updateData = [
                        'product_id' => $order->product_id,
                        'product_spec_snapshot' => $order->product_spec_snapshot ?? $order->product_name_snapshot,
                        'product_type_snapshot' => $order->product_type_snapshot,
                        'service_id' => $order->service_id,
                        'coupon_id' => $order->coupon_id,
                        'user_coupon_id' => $order->user_coupon_id,
                        'coupon_code' => $order->coupon_code,
                        'discount' => $order->discount ?? 0,
                        'billing_cycle' => $order->billing_cycle,
                        'quantity' => $order->quantity ?? 1,
                        'config_snapshot' => $order->config_snapshot,
                        'config_pricing_snapshot' => $order->config_pricing_snapshot,
                        'coupon_snapshot' => $order->coupon_snapshot,
                    ];

                    if (! $dryRun) {
                        DB::table('invoices')->where('id', $invoice->id)->update($updateData);
                    }

                    $updated++;
                }

                $this->line("已处理 {$processed}/{$this->totalCount()} 条");
            });

        // 回填 referral_rewards.invoice_id
        $rewardUpdated = 0;
        if (Schema::hasTable('referral_rewards') && Schema::hasColumn('referral_rewards', 'invoice_id')) {
            $rewards = DB::table('referral_rewards')
                ->whereNotNull('order_id')
                ->where('order_id', '>', 0)
                ->whereNull('invoice_id')
                ->get();

            foreach ($rewards as $reward) {
                $invoiceId = DB::table('invoices')
                    ->where('order_id', $reward->order_id)
                    ->value('id');

                if ($invoiceId && ! $dryRun) {
                    DB::table('referral_rewards')
                        ->where('id', $reward->id)
                        ->update(['invoice_id' => $invoiceId]);
                    $rewardUpdated++;
                } elseif ($invoiceId) {
                    $rewardUpdated++;
                }
            }
        }

        $this->newLine();
        $this->info('=== 回填结果 ===');
        $this->info("账单总数：{$totalInvoices}");
        $this->info("已处理：{$processed}");
        $this->info("已更新：{$updated}");
        $this->info("已跳过：{$skipped}");
        $this->info("返佣记录更新：{$rewardUpdated}");

        if ($dryRun) {
            $this->warn('这是预览模式，实际未写入。去掉 --dry-run 参数执行实际回填。');
        }

        return 0;
    }

    private function totalCount(): int
    {
        static $count;

        return $count ??= DB::table('invoices')->whereNotNull('order_id')->where('order_id', '>', 0)->count();
    }
}
