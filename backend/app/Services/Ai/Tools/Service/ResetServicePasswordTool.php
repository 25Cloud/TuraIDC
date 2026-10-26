<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Service;

use App\Exceptions\BusinessException;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\ClientServiceConsole\ServicePowerService;

/**
 * 重置实例密码。密码只会写进系统，绝不回显到对话里。
 */
final class ResetServicePasswordTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServicePowerService $power) {}

    public function name(): string
    {
        return 'reset_service_password';
    }

    public function description(): string
    {
        return '重置某台实例的登录密码。密码由用户在确认卡片里自己填写的参数决定，AI 不要在对话里复述密码。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'password' => ['type' => 'string', 'maxLength' => 64, 'description' => '新密码，至少 8 位'],
        ], ['service_id', 'password']);
    }

    public function risk(): string
    {
        return self::RISK_WRITE;
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function title(array $arguments): string
    {
        return '重置实例密码（实例 #' . (string) ($arguments['service_id'] ?? '') . '）';
    }

    public function impact(array $arguments): string
    {
        return '实例的旧密码立即失效，需要重启或重新登录生效；请确认已保存新密码。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $password = (string) $arguments['password'];
        if (mb_strlen($password) < 8) {
            throw new BusinessException('密码至少 8 位', 42200, 422);
        }

        $result = $this->power->resetPasswordForUser(
            $context->user,
            (int) $arguments['service_id'],
            ['password' => $password, 'password_confirmation' => $password],
            $context->actionContext('.password.reset'),
        );

        return [
            'summary' => '已提交密码重置（实例 #' . (int) $arguments['service_id'] . '），结果以控制台为准',
            'data' => [
                'service_id' => (int) $arguments['service_id'],
                'password_changed' => true,
                'result_excerpt' => $this->excerpt((string) json_encode($result, JSON_UNESCAPED_UNICODE), 600),
                'note' => '出于安全，密码不会出现在对话记录与审计里',
            ],
        ];
    }
}
