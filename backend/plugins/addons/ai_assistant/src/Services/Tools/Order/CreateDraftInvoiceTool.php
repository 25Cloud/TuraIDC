<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Order;

use App\Models\Invoice;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\Finance\CheckoutService;

/**
 * 创建未支付账单（即下单，不扣款）。
 *
 * 必须带 quote_price 返回的报价凭证，金额由后端二次比对，模型改不动价格。
 * 幂等键取工具调用记录编号，用户连点确认只会得到同一张账单。
 */
final class CreateDraftInvoiceTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly CheckoutService $checkout) {}

    public function name(): string
    {
        return 'create_draft_invoice';
    }

    public function description(): string
    {
        return '按报价凭证创建一张未支付账单（下单但不付款）。必须先用 quote_price 拿到 quote_token，且配置与周期保持一致。用户需要自己到账单页完成支付。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '商品编号'],
            'billing_cycle' => ['type' => 'string', 'maxLength' => 30, 'description' => '计费周期，必须与报价一致'],
            'quote_token' => ['type' => 'string', 'maxLength' => 120, 'description' => 'quote_price 返回的报价凭证'],
            'config' => ['type' => 'object', 'description' => '配置项键值对，必须与报价一致'],
            'user_coupon_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '优惠券编号，可选'],
        ], ['product_id', 'billing_cycle', 'quote_token']);
    }

    public function risk(): string
    {
        return self::RISK_WRITE;
    }

    public function scopes(): array
    {
        return ['orders'];
    }

    public function title(array $arguments): string
    {
        return '创建待支付账单';
    }

    public function impact(array $arguments): string
    {
        return '会占用库存并锁定优惠券，账单未支付前可取消；不会直接从余额扣款。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $invoice = $this->checkout->create(
            $context->userId(),
            [
                'product_id' => (int) $arguments['product_id'],
                'billing_cycle' => (string) $arguments['billing_cycle'],
                'quantity' => 1,
                'config' => is_array($arguments['config'] ?? null) ? $arguments['config'] : [],
                'quote_token' => (string) $arguments['quote_token'],
                'user_coupon_id' => (int) ($arguments['user_coupon_id'] ?? 0),
            ],
            [
                'idempotency_key' => $context->idempotencyKey !== '' ? $context->idempotencyKey : ('ai-draft-' . $context->conversation->id),
                'trace_id' => $context->traceId,
            ],
        );

        $amount = $invoice instanceof Invoice ? (string) $invoice->amount : '0.00';

        return [
            'summary' => '已创建账单 ' . ($invoice instanceof Invoice ? (string) $invoice->invoice_no : '') . '，应付 ' . $amount . ' 元',
            'data' => [
                'invoice_id' => $invoice instanceof Invoice ? (int) $invoice->id : 0,
                'invoice_no' => $invoice instanceof Invoice ? (string) $invoice->invoice_no : '',
                'amount' => $amount,
                'paid' => false,
                'next_step' => '到控制台「账单」页完成支付',
            ],
        ];
    }
}
