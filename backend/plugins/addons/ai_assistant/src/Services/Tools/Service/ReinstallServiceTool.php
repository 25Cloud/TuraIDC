<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Service;

use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\ClientServiceConsole\ServicePowerService;

/**
 * 重装系统：不可逆。
 *
 * 即使会话处于「自动审批」档位，危险工具也强制走确认卡片——这条硬底线写在执行器里，
 * 不依赖模型自觉。
 */
final class ReinstallServiceTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServicePowerService $power) {}

    public function name(): string
    {
        return 'reinstall_service';
    }

    public function description(): string
    {
        return '重装实例操作系统。会清空磁盘全部数据且不可恢复，必须先让用户明确同意，并在确认卡片上再次确认。os_id 取可重装系统列表里的标识。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'os_id' => ['type' => 'string', 'maxLength' => 50, 'description' => '目标系统标识（上游 os_id）'],
        ], ['service_id', 'os_id']);
    }

    public function risk(): string
    {
        return self::RISK_DANGEROUS;
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function title(array $arguments): string
    {
        return '重装系统（实例 #' . (string) ($arguments['service_id'] ?? '') . '）';
    }

    public function impact(array $arguments): string
    {
        return '磁盘数据将全部清空且不可恢复；重装期间实例不可用。请确认已完成备份。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $result = $this->power->reinstallForUser(
            $context->user,
            (int) $arguments['service_id'],
            ['os_id' => (string) $arguments['os_id']],
            $context->actionContext('.reinstall'),
        );

        return [
            'summary' => '已提交重装（实例 #' . (int) $arguments['service_id'] . '），系统将以 ' . (string) $arguments['os_id'] . ' 重装',
            'data' => [
                'service_id' => (int) $arguments['service_id'],
                'os_id' => (string) $arguments['os_id'],
                'result_excerpt' => $this->excerpt((string) json_encode($result, JSON_UNESCAPED_UNICODE), 600),
            ],
        ];
    }
}
