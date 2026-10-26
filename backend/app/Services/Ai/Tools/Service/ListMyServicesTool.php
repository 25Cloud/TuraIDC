<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Service;

use App\Constants\ServiceStatus;
use App\Models\Service;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\ClientServiceConsole\ServiceOverviewService;

/**
 * 我的实例列表：只读。返回归属当前用户的实例，不含他人数据。
 */
final class ListMyServicesTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServiceOverviewService $services) {}

    public function name(): string
    {
        return 'list_my_services';
    }

    public function description(): string
    {
        return '列出当前用户名下的服务实例（编号、名称、状态、到期时间）。用户问「我有哪些机器」「哪台快到期了」时先用它。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => '返回条数，默认 20'],
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'active', 'suspended', 'expired', 'cancelled'],
                'description' => '按状态筛选，可选',
            ],
        ]);
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 20);
        $filters = ['page' => 1, 'page_size' => $limit];

        $statusMap = [
            'pending' => ServiceStatus::PENDING,
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'expired' => ServiceStatus::EXPIRED,
            'cancelled' => ServiceStatus::CANCELLED,
        ];
        if (isset($arguments['status'], $statusMap[(string) $arguments['status']])) {
            $filters['status'] = $statusMap[(string) $arguments['status']];
        }

        $items = [];
        foreach ($this->toList($this->services->paginateForUser($context->user, $filters)) as $row) {
            if ($row instanceof Service) {
                $status = (int) $row->status;
                $items[] = [
                    'service_id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'status' => $status,
                    'status_text' => (string) (ServiceStatus::$labels[$status] ?? '未知'),
                    'expires_at' => (string) ($row->expires_at?->format('Y-m-d H:i:s') ?? ''),
                ];
                continue;
            }
            if (is_array($row)) {
                $status = (int) ($row['status'] ?? 0);
                $items[] = [
                    'service_id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'status' => $status,
                    'status_text' => (string) ($row['status_text'] ?? ServiceStatus::$labels[$status] ?? '未知'),
                    'expires_at' => (string) ($row['expires_at'] ?? ''),
                ];
            }
        }

        return [
            'summary' => $items === []
                ? '当前用户名下没有符合条件的实例'
                : '共 ' . count($items) . ' 台实例：' . implode('、', array_map(
                    static fn (array $item): string => $item['name'] . '（' . $item['status_text'] . '）',
                    array_slice($items, 0, 5),
                )),
            'data' => ['items' => $items, 'total' => count($items)],
        ];
    }
}
