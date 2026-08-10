<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\TradeMigrationService;
use Illuminate\Console\Command;

class ReconcileTradeDomainCommand extends Command
{
    protected $signature = 'migrate:trade:reconcile {--json : 以 JSON 输出结果}';

    protected $description = '执行交易与支付域迁移后的总对账';

    public function handle(TradeMigrationService $service): int
    {
        $service->ensureConnections();

        $summary = [
            'invoices' => [
                'old' => $service->sourceCount('invoices'),
                'new' => $service->targetCount('invoices'),
                'old_total_amount' => $service->sourceSum('invoices', 'amount'),
                'new_total_amount' => $service->targetSum('invoices', 'total_amount'),
                'old_paid_amount' => $service->sourceSum('invoices', 'paid_amount'),
                'new_paid_amount' => $service->targetSum('invoices', 'paid_amount'),
            ],
            'invoice_items' => [
                'old' => $service->sourceCount('invoice_items'),
                'new' => $service->targetCount('invoice_items'),
            ],
            'payments' => [
                'old' => $service->sourceCount('payments'),
                'new' => $service->targetCount('payments'),
                'old_success_amount' => $service->sourceSum('payments', 'amount', 'status = 1'),
                'new_success_amount' => $service->targetSum('payments', 'amount', 'status = 1'),
            ],
            'payment_callbacks' => [
                'old' => $service->sourceCount('payment_callbacks'),
                'new' => $service->targetCount('payment_callbacks'),
            ],
            'refunds' => [
                'old_derived' => count($service->deriveRefundPayloads()),
                'new' => $service->targetCount('refunds'),
                'new_refund_amount' => $service->targetSum('refunds', 'amount'),
            ],
        ];

        $orphans = [
            'invoices.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM invoices WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'invoices.product_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM invoices WHERE product_id IS NOT NULL AND product_id NOT IN (SELECT id FROM products)'
            )[0]->cnt) ?? 0),
            'invoices.service_instance_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM invoices WHERE service_instance_id IS NOT NULL AND service_instance_id NOT IN (SELECT id FROM service_instances)'
            )[0]->cnt) ?? 0),
            'invoice_items.invoice_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM invoice_items WHERE invoice_id NOT IN (SELECT id FROM invoices)'
            )[0]->cnt) ?? 0),
            'payments.invoice_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM payments WHERE invoice_id NOT IN (SELECT id FROM invoices)'
            )[0]->cnt) ?? 0),
            'payments.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM payments WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
            'payment_callbacks.payment_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM payment_callbacks WHERE payment_id NOT IN (SELECT id FROM payments)'
            )[0]->cnt) ?? 0),
            'refunds.payment_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM refunds WHERE payment_id NOT IN (SELECT id FROM payments)'
            )[0]->cnt) ?? 0),
            'refunds.invoice_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM refunds WHERE invoice_id NOT IN (SELECT id FROM invoices)'
            )[0]->cnt) ?? 0),
            'refunds.user_id' => (int) (($service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM refunds WHERE user_id NOT IN (SELECT id FROM users)'
            )[0]->cnt) ?? 0),
        ];

        $payload = [
            'summary' => $summary,
            'orphans' => $orphans,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($summary as $table => $counts) {
            $this->line($table.': '.json_encode($counts, JSON_UNESCAPED_UNICODE));
        }

        foreach ($orphans as $key => $count) {
            $this->line($key.' orphan='.$count);
        }

        return self::SUCCESS;
    }
}
