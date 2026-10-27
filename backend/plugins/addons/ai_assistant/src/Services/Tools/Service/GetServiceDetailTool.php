<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Service;

use App\Constants\ServiceStatus;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\ClientServiceConsole\ServiceDetailService;

/**
 * 实例详情：只读。归属校验由 ServiceDetailService::getDetailForUser 负责。
 */
final class GetServiceDetailTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServiceDetailService $details) {}

    public function name(): string
    {
        return 'get_service_detail';
    }

    public function description(): string
    {
        return '查看某台实例的详情（状态、到期时间、规格、连接信息摘要）。用户问「我这个机器什么配置」时用。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'refresh' => ['type' => 'boolean', 'description' => '是否强制向上游刷新一次状态，默认否'],
        ], ['service_id']);
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $detail = $this->details->getDetailForUser(
            $context->user,
            (int) $arguments['service_id'],
            (bool) ($arguments['refresh'] ?? false),
        );

        $status = (int) $this->pick($detail, 'status', 0);
        $name = (string) $this->pick($detail, 'name', '');
        $expiresAt = (string) $this->pick($detail, 'expires_at', '');
        $statusText = (string) (ServiceStatus::$labels[$status] ?? '未知');

        return [
            'summary' => sprintf('实例「%s」当前%s，到期时间 %s', $name !== '' ? $name : ('#' . $arguments['service_id']), $statusText, $expiresAt !== '' ? $expiresAt : '未知'),
            'data' => [
                'service_id' => (int) $arguments['service_id'],
                'name' => $name,
                'status' => $status,
                'status_text' => $statusText,
                'expires_at' => $expiresAt,
                'detail_excerpt' => $this->excerpt((string) json_encode($detail, JSON_UNESCAPED_UNICODE), 1200),
            ],
        ];
    }
}
