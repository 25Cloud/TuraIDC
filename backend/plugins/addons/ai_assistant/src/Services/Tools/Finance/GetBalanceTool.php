<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Finance;

use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\User\AccountService;

/**
 * 余额查询：只读，走 AccountService 的读取方法。
 *
 * 严禁在此改余额：AccountService 的写入方法自身不校验负数，防负余额逻辑散落在
 * PaymentService 等调用点，工具层一旦放开就等于绕过全部校验。
 */
final class GetBalanceTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly AccountService $accounts) {}

    public function name(): string
    {
        return 'get_balance';
    }

    public function description(): string
    {
        return '查询当前账户的可用余额与信用额度。用于回答「我余额够不够」「还差多少钱」这类问题。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([]);
    }

    public function scopes(): array
    {
        return ['finance'];
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $balance = $this->accounts->cashBalance($context->user);
        $credit = method_exists($context->user, 'credit_limit') ? $context->user->credit_limit() : 0;

        return [
            'summary' => '当前余额 ' . number_format($balance, 2, '.', '') . ' 元',
            'data' => [
                'balance' => number_format($balance, 2, '.', ''),
                'balance_cents' => $this->toCents($balance),
                'credit_limit' => number_format((float) $credit, 2, '.', ''),
                'note' => '余额可用于支付账单，AI 不会自动扣款，任何扣款都需要你在确认卡片上点击确认',
            ],
        ];
    }
}
