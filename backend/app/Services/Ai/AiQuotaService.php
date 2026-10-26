<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Models\AiUsageDaily;
use App\Models\User;
use App\Services\Integrations\Ai\Data\AiUsage;

/**
 * 额度与熔断：用户日额度、全站日额度、全站日成本三道闸。
 *
 * 计数落 ai_usage_daily（按天按用户一行），不依赖 Redis，天然可对账。
 * 超限不抛 500，而是 42900 + 中文提示，前端据此提示「明天再来」。
 */
final class AiQuotaService
{
    public function __construct(private readonly AiConfig $config) {}

    /**
     * @return array{daily_messages: int, daily_tokens: int, used_messages: int, used_tokens: int, resets_at: string|null}
     */
    public function snapshot(User $user): array
    {
        $row = $this->todayRow((int) $user->id);

        return [
            'daily_messages' => $this->config->userDailyMessages(),
            'daily_tokens' => $this->config->userDailyTokens(),
            'used_messages' => (int) ($row?->messages ?? 0),
            'used_tokens' => (int) (($row?->prompt_tokens ?? 0) + ($row?->completion_tokens ?? 0)),
            'resets_at' => now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    public function assertWithinQuota(User $user): void
    {
        $snapshot = $this->snapshot($user);
        $maxMessages = $snapshot['daily_messages'];
        $maxTokens = $snapshot['daily_tokens'];

        if ($maxMessages > 0 && $snapshot['used_messages'] >= $maxMessages) {
            throw new BusinessException('今日 AI 对话次数已用完，请明天再试', 42900, 429);
        }
        if ($maxTokens > 0 && $snapshot['used_tokens'] >= $maxTokens) {
            throw new BusinessException('今日 AI 用量已用完，请明天再试', 42900, 429);
        }

        $this->assertGlobalBudget();
    }

    public function assertGlobalBudget(): void
    {
        $today = now()->toDateString();
        $totals = AiUsageDaily::query()
            ->where('day', $today)
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens, COALESCE(SUM(cost_cents), 0) AS cost')
            ->first();

        $tokens = (int) ($totals->tokens ?? 0);
        $cost = (int) ($totals->cost ?? 0);

        $maxTokens = $this->config->globalDailyTokens();
        if ($maxTokens > 0 && $tokens >= $maxTokens) {
            throw new BusinessException('站点今日 AI 总额度已用尽，请明天再试或联系管理员', 42900, 429);
        }

        $maxCost = $this->config->globalDailyCostCents();
        if ($maxCost > 0 && $cost >= $maxCost) {
            throw new BusinessException('站点今日 AI 成本已达上限，请明天再试或联系管理员', 42900, 429);
        }
    }

    public function record(User $user, AiUsage $usage): void
    {
        $row = $this->todayRow((int) $user->id, true);
        if (! $row instanceof AiUsageDaily) {
            return;
        }

        $tokens = max($usage->total(), 0);
        $cost = (int) ceil($tokens / 1000 * $this->config->costPerThousandTokensCents());

        $row->messages = (int) $row->messages + 1;
        $row->prompt_tokens = (int) $row->prompt_tokens + $usage->promptTokens;
        $row->completion_tokens = (int) $row->completion_tokens + $usage->completionTokens;
        $row->cost_cents = (int) $row->cost_cents + $cost;
        $row->save();
    }

    private function todayRow(int $userId, bool $create = false): ?AiUsageDaily
    {
        $today = now()->toDateString();
        $query = AiUsageDaily::query()->where('day', $today)->where('user_id', $userId);

        $row = $query->first();
        if ($row instanceof AiUsageDaily || ! $create) {
            return $row;
        }

        return AiUsageDaily::query()->create([
            'day' => $today,
            'user_id' => $userId,
            'messages' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_cents' => 0,
        ]);
    }
}
