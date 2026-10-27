<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Order;

use App\Constants\InvoiceStatus;
use App\Models\Invoice;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\Finance\InvoiceV2QueryService;

/**
 * 我的账单：只读查询，直接走查询服务的纯读方法。
 *
 * 不走控制器：GET /client/invoices 会先取消过期未付账单，属于写库副作用，工具层不能碰。
 */
final class ListMyInvoicesTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly InvoiceV2QueryService $invoices) {}

    public function name(): string
    {
        return 'list_my_invoices';
    }

    public function description(): string
    {
        return '列出当前用户最近的账单（编号、金额、状态、创建时间）。用于回答「我还有没有没付的账单」「上个月扣了多少」。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => '返回条数，默认 10'],
            'status' => [
                'type' => 'string',
                'enum' => ['unpaid', 'paid', 'cancelled', 'overdue', 'refunded'],
                'description' => '按状态筛选，可选',
            ],
        ]);
    }

    public function scopes(): array
    {
        return ['orders'];
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 10);
        $filters = ['page' => 1, 'page_size' => $limit];

        $statusMap = [
            'unpaid' => InvoiceStatus::UNPAID,
            'paid' => InvoiceStatus::PAID,
            'cancelled' => InvoiceStatus::CANCELLED,
            'overdue' => InvoiceStatus::OVERDUE,
            'refunded' => InvoiceStatus::REFUNDED,
        ];
        if (isset($arguments['status'], $statusMap[(string) $arguments['status']])) {
            $filters['status'] = $statusMap[(string) $arguments['status']];
        }

        $items = [];
        foreach ($this->toList($this->invoices->paginateClientInvoices($context->userId(), $filters)) as $row) {
            if ($row instanceof Invoice) {
                $items[] = [
                    'invoice_id' => (int) $row->id,
                    'invoice_no' => (string) $row->invoice_no,
                    'amount' => number_format((float) $row->amount, 2, '.', ''),
                    'status' => (int) $row->status,
                    'status_text' => (string) (InvoiceStatus::$labels[(int) $row->status] ?? '未知'),
                    'created_at' => (string) ($row->created_at?->format('Y-m-d H:i:s') ?? ''),
                    'due_date' => (string) ($row->due_date ?? ''),
                ];
                continue;
            }
            if (is_array($row)) {
                $status = (int) ($row['status'] ?? 0);
                $items[] = [
                    'invoice_id' => (int) ($row['id'] ?? 0),
                    'invoice_no' => (string) ($row['invoice_no'] ?? ''),
                    'amount' => (string) ($row['amount'] ?? '0.00'),
                    'status' => $status,
                    'status_text' => (string) (InvoiceStatus::$labels[$status] ?? '未知'),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'due_date' => (string) ($row['due_date'] ?? ''),
                ];
            }
        }

        return [
            'summary' => $items === []
                ? '当前没有符合条件的账单'
                : '最近 ' . count($items) . ' 条账单，未付 ' . count(array_filter($items, static fn (array $item): bool => $item['status'] === InvoiceStatus::UNPAID)) . ' 条',
            'data' => ['items' => $items, 'total' => count($items)],
        ];
    }
}
