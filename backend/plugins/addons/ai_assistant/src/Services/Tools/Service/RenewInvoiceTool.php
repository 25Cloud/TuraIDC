<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Service;

use App\Models\Invoice;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\Provisioning\ServiceRenewService;

/**
 * 创建续费账单（只建单，不自动扣款）。
 */
final class RenewInvoiceTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ServiceRenewService $renewals) {}

    public function name(): string
    {
        return 'renew_invoice';
    }

    public function description(): string
    {
        return '为某台实例创建续费账单（只建单，不自动扣款）。创建前应先用 get_renew_quote 报价给用户看。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'billing_cycle' => [
                'type' => 'string',
                'maxLength' => 30,
                'enum' => ['monthly', 'quarterly', 'semiannually', 'annually'],
                'description' => '续费周期',
            ],
            'user_coupon_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '优惠券编号，可选'],
        ], ['service_id', 'billing_cycle']);
    }

    public function risk(): string
    {
        return self::RISK_WRITE;
    }

    public function scopes(): array
    {
        return ['services', 'orders'];
    }

    public function title(array $arguments): string
    {
        return '创建续费账单';
    }

    public function impact(array $arguments): string
    {
        return '会生成一张未支付账单；未支付不会延长到期时间，也不会扣余额。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $invoice = $this->renewals->createRenewInvoiceForUser(
            $context->user,
            (int) $arguments['service_id'],
            (string) $arguments['billing_cycle'],
            (int) ($arguments['user_coupon_id'] ?? 0),
            array_merge($context->actionContext('.renew'), [
                'idempotency_key' => $context->idempotencyKey,
            ]),
        );

        return [
            'summary' => '已创建续费账单 ' . ($invoice instanceof Invoice ? (string) $invoice->invoice_no : '') . '，应付 ' . ($invoice instanceof Invoice ? (string) $invoice->amount : '0.00') . ' 元',
            'data' => [
                'invoice_id' => $invoice instanceof Invoice ? (int) $invoice->id : 0,
                'invoice_no' => $invoice instanceof Invoice ? (string) $invoice->invoice_no : '',
                'amount' => $invoice instanceof Invoice ? (string) $invoice->amount : '0.00',
                'next_step' => '到控制台「账单」页完成支付后自动续期',
            ],
        ];
    }
}
