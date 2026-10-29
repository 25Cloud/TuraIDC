<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Services\ProductCatalog\ProductV2QueryService;
use App\Services\Site\SiteProductQuoteService;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\DescribesProductForSale;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;

/**
 * 实时报价：唯一的合法价格来源。
 *
 * 顺带签发 quote_token（下单一环必须带），因此模型拿到报价后可以直接进入建单确认。
 * 这里只签单不落库、不扣款。
 */
final class QuotePriceTool extends AiTool
{
    use DescribesProductForSale;
    use FormatsToolResults;

    public function __construct(
        private readonly ProductV2QueryService $products,
        private readonly SiteProductQuoteService $quotes,
    ) {}

    public function name(): string
    {
        return 'quote_price';
    }

    public function description(): string
    {
        return '按商品、计费周期与配置项计算实时价格（含代理折扣与可用优惠券），返回金额明细与报价凭证。所有价格必须以本工具结果为准，禁止自行报价。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '商品编号'],
            'billing_cycle' => [
                'type' => 'string',
                'maxLength' => 30,
                'enum' => ['monthly', 'quarterly', 'semiannually', 'annually'],
                'description' => '计费周期：monthly 月付 / quarterly 季付 / semiannually 半年付 / annually 年付',
            ],
            'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => '数量，默认 1'],
            'config' => ['type' => 'object', 'description' => '配置项键值对，键取 get_product_detail 返回的 field'],
            'user_coupon_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '要使用的优惠券编号，可选'],
        ], ['product_id', 'billing_cycle']);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        try {
            $product = $this->products->findSiteProduct((int) $arguments['product_id']);
        } catch (\Throwable) {
            throw new BusinessException('商品不存在或已下架', 40400, 404);
        }
        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在或已下架', 40400, 404);
        }

        $validated = [
            'billing_cycle' => (string) $arguments['billing_cycle'],
            'quantity' => (int) ($arguments['quantity'] ?? 1),
            'config' => is_array($arguments['config'] ?? null) ? $arguments['config'] : [],
        ];
        if (isset($arguments['user_coupon_id'])) {
            $validated['user_coupon_id'] = (int) $arguments['user_coupon_id'];
        }

        $quote = $this->quotes->quoteForUser($product, $validated, $context->user, [
            'user_id' => $context->userId(),
            'request_id' => 'ai-quote-' . $context->conversation->id,
            'ip_address' => '',
        ]);

        $items = [];
        foreach ((array) ($quote['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'label' => (string) ($item['label'] ?? ''),
                'value_label' => (string) ($item['value_label'] ?? ''),
                'amount' => (string) ($item['amount'] ?? '0.00'),
            ];
        }

        $total = (string) ($quote['total_amount'] ?? '0.00');
        $original = (string) ($quote['original_total_amount'] ?? $total);
        $presentation = $this->describeProductForSale($product);

        return [
            'summary' => sprintf(
                '报价：%s（%s）/ %s，原价 %s，应付 %s',
                (string) $presentation['title'],
                (string) $presentation['group_path'],
                (string) $arguments['billing_cycle'],
                $original,
                $total,
            ),
            'data' => [
                ...$presentation,
                'billing_cycle' => (string) $arguments['billing_cycle'],
                'quantity' => (int) $validated['quantity'],
                'original_total_amount' => $original,
                'total_amount' => $total,
                'discount_amount' => (string) ($quote['discount_amount'] ?? '0.00'),
                'agent_discount_amount' => (string) ($quote['agent_discount_amount'] ?? '0.00'),
                'amount_cents' => $this->toCents($total),
                'items' => $items,
                'coupon' => is_array($quote['coupon'] ?? null) ? [
                    'user_coupon_id' => (int) ($quote['coupon']['user_coupon_id'] ?? 0),
                    'discount_amount' => (string) ($quote['coupon']['discount_amount'] ?? '0.00'),
                ] : null,
                'quote_expires_at' => (string) ($quote['quote_expires_at'] ?? ''),
                'note' => '下单时必须带同一份报价凭证与相同的配置，改配置需要重新报价；报金额时把 title 与 product_url 一起给用户，不要只报一个数字',
            ],
        ];
    }
}
