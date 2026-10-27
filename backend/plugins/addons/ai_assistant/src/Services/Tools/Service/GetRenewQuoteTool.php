<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Service;

use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\Provisioning\ServiceRenewService;

/**
 * 续费报价：只读预览，不建单不扣款。续费金额一律以本工具结果为准。
 */
final class GetRenewQuoteTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServiceRenewService $renewals) {}

    public function name(): string
    {
        return 'get_renew_quote';
    }

    public function description(): string
    {
        return '查询某台实例续费需要多少钱（可指定计费周期与优惠券）。只报价，不创建账单、不扣款。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'billing_cycle' => [
                'type' => 'string',
                'maxLength' => 30,
                'enum' => ['monthly', 'quarterly', 'semiannually', 'annually'],
                'description' => '计费周期，留空取实例当前周期',
            ],
            'user_coupon_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '优惠券编号，可选'],
        ], ['service_id']);
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $cycle = isset($arguments['billing_cycle']) ? (string) $arguments['billing_cycle'] : null;
        $preview = $this->renewals->previewForUser(
            $context->user,
            (int) $arguments['service_id'],
            $cycle,
            (int) ($arguments['user_coupon_id'] ?? 0),
        );

        $amount = $this->pick($preview, 'amount', $this->pick($preview, 'total_amount', null));
        $amountText = is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '未知';

        return [
            'summary' => '续费报价 ' . $amountText . ' 元（周期：' . (string) ($cycle ?? $this->pick($preview, 'billing_cycle', '当前周期')) . '）',
            'data' => [
                'service_id' => (int) $arguments['service_id'],
                'billing_cycle' => (string) ($cycle ?? $this->pick($preview, 'billing_cycle', '')),
                'amount' => $amountText,
                'amount_cents' => $this->toCents($amount),
                'preview_excerpt' => $this->excerpt((string) json_encode($preview, JSON_UNESCAPED_UNICODE), 1200),
                'note' => '确认后调用 renew_invoice 创建续费账单（只建单，不自动扣款）',
            ],
        ];
    }
}
