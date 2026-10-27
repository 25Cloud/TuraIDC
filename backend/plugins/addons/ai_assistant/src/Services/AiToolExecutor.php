<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services;

use App\Exceptions\BusinessException;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiToolInvocation;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiSchemaValidator;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolRegistry;
use Illuminate\Support\Str;
use Throwable;

/**
 * 工具执行器：风险分级 + 确认协议的唯一实现。
 *
 * 规则（写死在代码里，不依赖模型自觉）：
 * - read：立即执行；
 * - write：confirm 档位下生成待确认动作；auto_approve 档位下直接执行；
 * - dangerous：任何档位都必须用户确认（自动审批也不放行）；
 * - read_only 档位下 write/dangerous 一律拒绝并给出中文原因。
 */
final class AiToolExecutor
{
    public function __construct(
        private readonly AiToolRegistry $registry,
        private readonly AiSchemaValidator $validator,
        private readonly AiConfig $config,
        private readonly AiConfirmToken $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{tool: string, risk: string, status: string, summary: string, data: mixed, pending: array<string, mixed>|null, invocation_id: int}
     */
    public function dispatch(AiToolContext $context, string $toolName, array $arguments, ?int $messageId = null): array
    {
        $tool = $this->registry->get($toolName);
        if (! $tool instanceof AiTool) {
            return $this->outcome($toolName, 'read', 'failed', '该能力不存在或未授权，无法执行', null, 0);
        }

        if ($context->permissionMode === \TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation::MODE_READ_ONLY && $tool->risk() !== AiTool::RISK_READ) {
            return $this->outcome(
                $toolName,
                $tool->risk(),
                'rejected',
                '当前处于「仅可查看」模式，不能执行该操作；请引导用户把权限切到「完全权限」',
                null,
                0,
            );
        }

        try {
            $clean = $this->validator->validate($tool->parameters(), $this->stripReserved($arguments));
        } catch (BusinessException $exception) {
            return $this->outcome($toolName, $tool->risk(), 'failed', $exception->getMessage(), null, 0);
        }

        $needsConfirm = $tool->risk() === AiTool::RISK_DANGEROUS
            || ($tool->risk() === AiTool::RISK_WRITE && $context->permissionMode !== \TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation::MODE_AUTO_APPROVE);

        if ($needsConfirm) {
            $invocation = $this->createPending($context, $tool, $clean, $messageId);
            $presented = $this->presentPending($invocation);

            return [
                'tool' => $tool->name(),
                'risk' => $tool->risk(),
                'status' => 'pending',
                'summary' => '等待用户确认：' . (string) ($presented['title'] ?? $tool->name()),
                'data' => [
                    'status' => 'awaiting_confirmation',
                    'action_id' => (int) $invocation->id,
                    'instruction' => '动作已挂起等待用户在确认卡片上点击确认，不要声称已经执行完成',
                ],
                'pending' => $presented,
                'invocation_id' => (int) $invocation->id,
            ];
        }

        return $this->executeNow($context, $tool, $clean, $messageId);
    }

    /**
     * 用户点击确认后执行。
     *
     * @return array{tool: string, risk: string, status: string, summary: string, data: mixed}
     */
    public function confirm(AiToolInvocation $invocation, string $token): array
    {
        $this->assertConfirmable($invocation, $token);

        $tool = $this->registry->get((string) $invocation->tool_name);
        if (! $tool instanceof AiTool) {
            $this->markFailed($invocation, '该能力不存在或未授权');

            return ['tool' => (string) $invocation->tool_name, 'risk' => (string) $invocation->risk, 'status' => 'failed', 'summary' => '该能力不存在或未授权', 'data' => null];
        }

        $context = new AiToolContext(
            $invocation->user,
            $invocation->conversation,
            (string) ($invocation->conversation?->channel ?? 'console'),
            (string) ($invocation->conversation?->permission_mode ?? \TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation::MODE_CONFIRM),
            'ai-confirm-' . $invocation->id,
            'ai-inv-' . $invocation->id,
        );

        try {
            $clean = $this->validator->validate($tool->parameters(), $this->stripReserved((array) $invocation->arguments));
        } catch (BusinessException $exception) {
            $this->markFailed($invocation, $exception->getMessage());

            return ['tool' => $tool->name(), 'risk' => $tool->risk(), 'status' => 'failed', 'summary' => $exception->getMessage(), 'data' => null];
        }

        return $this->runAndRecord($context, $tool, $clean, $invocation);
    }

    public function reject(AiToolInvocation $invocation): void
    {
        $invocation->status = AiToolInvocation::STATUS_REJECTED;
        $invocation->confirmed_at = now();
        $invocation->save();
    }

    public function assertConfirmable(AiToolInvocation $invocation, string $token): void
    {
        if (! $invocation->isPending()) {
            throw new BusinessException('该操作已处理，请刷新页面', 40900, 409);
        }

        $expiresAt = $invocation->created_at?->copy()->addMinutes($this->config->confirmTtlMinutes());
        if (! $expiresAt || $expiresAt->isPast()) {
            $invocation->status = AiToolInvocation::STATUS_FAILED;
            $invocation->error_code = 'expired';
            $invocation->save();

            throw new BusinessException('确认已超时，请让助手重新发起该操作', 40900, 409);
        }

        if (! $this->tokens->verify($invocation, $token)) {
            throw new BusinessException('确认凭证无效，请刷新页面后重试', 40300, 403);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{tool: string, risk: string, status: string, summary: string, data: mixed, pending: array<string, mixed>|null, invocation_id: int}
     */
    private function executeNow(AiToolContext $context, AiTool $tool, array $arguments, ?int $messageId): array
    {
        $invocation = AiToolInvocation::query()->create([
            'conversation_id' => (int) $context->conversation->id,
            'message_id' => $messageId,
            'user_id' => $context->userId(),
            'tool_name' => $tool->name(),
            'risk' => $tool->risk(),
            'arguments' => $arguments,
            'status' => AiToolInvocation::STATUS_DONE,
            'idempotency_key' => 'ai-inv-' . Str::uuid()->toString(),
        ]);

        $executed = $this->runAndRecord($context, $tool, $arguments, $invocation);

        return [
            'tool' => $executed['tool'],
            'risk' => $executed['risk'],
            'status' => $executed['status'],
            'summary' => $executed['summary'],
            'data' => $executed['data'],
            'pending' => null,
            'invocation_id' => (int) $invocation->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{tool: string, risk: string, status: string, summary: string, data: mixed}
     */
    private function runAndRecord(AiToolContext $context, AiTool $tool, array $arguments, AiToolInvocation $invocation): array
    {
        $startedAt = microtime(true);

        try {
            $result = $tool->run($context, $arguments);
            $summary = (string) ($result['summary'] ?? '已完成');
            $data = $result['data'] ?? null;

            $invocation->status = AiToolInvocation::STATUS_CONFIRMED;
            $invocation->result = ['summary' => $summary];
            $invocation->duration_ms = (int) round((microtime(true) - $startedAt) * 1000);
            $invocation->confirmed_at = now();
            $invocation->save();
        } catch (BusinessException $exception) {
            $summary = $exception->getMessage();
            $data = null;
            $this->markFailed($invocation, $summary);
        } catch (Throwable $exception) {
            // 第三方/上游异常统一中文兜底，原文只进日志
            report($exception);
            $summary = '操作失败，请稍后重试或联系人工客服';
            $data = null;
            $this->markFailed($invocation, $summary);
        }

        return [
            'tool' => $tool->name(),
            'risk' => $tool->risk(),
            'status' => $invocation->status === AiToolInvocation::STATUS_CONFIRMED ? 'done' : 'failed',
            'summary' => $summary,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createPending(AiToolContext $context, AiTool $tool, array $arguments, ?int $messageId): AiToolInvocation
    {
        $meta = array_merge($arguments, [
            '__title' => $tool->title($arguments),
            '__description' => $tool->description(),
            '__summary' => $this->summarizeArguments($arguments),
            '__impact' => $tool->impact($arguments),
            '__amount_cents' => $tool->amountCents($arguments),
        ]);

        return AiToolInvocation::query()->create([
            'conversation_id' => (int) $context->conversation->id,
            'message_id' => $messageId,
            'user_id' => $context->userId(),
            'tool_name' => $tool->name(),
            'risk' => $tool->risk(),
            'arguments' => $meta,
            'status' => AiToolInvocation::STATUS_PENDING,
            'idempotency_key' => 'ai-inv-' . Str::uuid()->toString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPending(AiToolInvocation $invocation): array
    {
        $arguments = is_array($invocation->arguments) ? $invocation->arguments : [];
        $token = $this->tokens->issue($invocation);

        $invocation->confirm_token_hash = $this->tokens->hash($token);
        $invocation->save();

        return [
            'id' => (int) $invocation->id,
            'tool' => (string) $invocation->tool_name,
            'risk' => (string) $invocation->risk,
            'title' => (string) ($arguments['__title'] ?? $invocation->tool_name),
            'description' => (string) ($arguments['__description'] ?? ''),
            'arguments_summary' => (string) ($arguments['__summary'] ?? ''),
            'impact' => (string) ($arguments['__impact'] ?? ''),
            'amount_cents' => is_numeric($arguments['__amount_cents'] ?? null) ? (int) $arguments['__amount_cents'] : null,
            'expires_at' => (string) ($invocation->created_at?->copy()->addMinutes($this->config->confirmTtlMinutes())->format('Y-m-d H:i:s') ?? ''),
            'confirm_token' => $token,
        ];
    }

    /**
     * 去掉确认卡片用的元字段，只把业务参数交给工具。
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function stripReserved(array $arguments): array
    {
        $clean = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key) && str_starts_with($key, '__')) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function summarizeArguments(array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $key => $value) {
            if (in_array($key, ['password', 'password_confirmation'], true)) {
                $parts[] = $key . '=******';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . (string) $value;
                continue;
            }
            $parts[] = $key . '=' . (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return implode('，', $parts);
    }

    private function markFailed(AiToolInvocation $invocation, string $reason): void
    {
        $invocation->status = AiToolInvocation::STATUS_FAILED;
        $invocation->result = ['summary' => $reason];
        $invocation->error_code = 'tool_failed';
        $invocation->confirmed_at = now();
        $invocation->save();
    }

    /**
     * @return array{tool: string, risk: string, status: string, summary: string, data: mixed, pending: null, invocation_id: int}
     */
    private function outcome(string $tool, string $risk, string $status, string $summary, mixed $data, int $invocationId): array
    {
        return [
            'tool' => $tool,
            'risk' => $risk,
            'status' => $status,
            'summary' => $summary,
            'data' => $data,
            'pending' => null,
            'invocation_id' => $invocationId,
        ];
    }
}
