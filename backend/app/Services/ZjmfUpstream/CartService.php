<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\BillingCycle;
use App\Models\Product;
use App\Models\User;
use App\Models\ZjmfUpstreamBinding;
use App\Services\Finance\CheckoutService;
use App\Services\Site\SiteProductQuoteService;
use Illuminate\Support\Facades\Log;

/**
 * 上游购物车/下单（被魔方财务对接）。
 *
 * 魔方财务 Host 逻辑的下单时序：
 *   GET  /user_info        -> user.currency 作为后续 currencyid
 *   POST /cart/clear       -> 携带 downstream 绑定；返回 hostid/invoiceid 表示已存在订单
 *   POST /cart/add_to_shop -> 校验商品并加入购物车
 *   POST /cart/settle      -> 创建账单，返回 {status, data:{hostid[], invoiceid}}
 * 后续 apply_credit（P6）用余额支付账单并开通。
 */
class CartService
{
    public function __construct(
        private readonly SiteProductQuoteService $quoteService,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function userInfo(User $user): array
    {
        return [
            'status' => 200,
            'msg' => '请求成功',
            'user' => [
                'id' => (int) $user->id,
                'email' => (string) ($user->email ?? ''),
                'username' => (string) ($user->username ?? ''),
                'currency' => 1,
            ],
        ];
    }

    /**
     * cart/clear：记录下游绑定，幂等返回成功。
     * 魔方财务把本接口当作「下单起点」：返回 hostid/invoiceid 表示已有订单，
     * 不返回则走 add_to_shop -> settle 流程。
     *
     * @return array<string, mixed>
     */
    public function clear(array $data): array
    {
        return [
            'status' => 200,
            'msg' => '操作成功',
        ];
    }

    /**
     * cart/add_to_shop：校验商品可售并记录购物车项（幂等）。
     *
     * @return array<string, mixed>
     */
    public function addToShop(User $user, array $data): array
    {
        $productId = (int) ($data['pid'] ?? 0);
        $product = Product::query()->where('id', $productId)->where('status', 1)->first();

        if (! $product instanceof Product) {
            return ['status' => 400, 'msg' => '商品不存在或已下架'];
        }

        $cycle = BillingCycle::normalize((string) ($data['billingcycle'] ?? ''));
        if ($cycle === '' || BillingCycle::months($cycle) === null && $cycle !== BillingCycle::ONE_TIME && $cycle !== BillingCycle::FREE) {
            return ['status' => 400, 'msg' => '计费周期无效'];
        }

        return [
            'status' => 200,
            'msg' => '已加入购物车',
        ];
    }

    /**
     * cart/settle：创建新购账单并记录下游绑定。
     *
     * 魔方财务 settle 请求体（http_build_query）：
     *   cart_data{pid, billingcycle, host, password, currencyid, qty, configoptions, customfield}
     *   downstream_url / downstream_token / downstream_id
     *
     * @return array<string, mixed>
     */
    public function settle(User $user, array $cartData, array $downstream): array
    {
        $productId = (int) ($cartData['pid'] ?? 0);
        $billingCycle = BillingCycle::normalize((string) ($cartData['billingcycle'] ?? ''));
        $quantity = max((int) ($cartData['qty'] ?? 1), 1);
        $product = Product::query()->where('id', $productId)->where('status', 1)->first();

        if (! $product instanceof Product) {
            return ['status' => 400, 'msg' => '商品不存在或已下架'];
        }
        if ($billingCycle === '') {
            return ['status' => 400, 'msg' => '计费周期无效'];
        }

        $downstreamId = (int) ($downstream['downstream_id'] ?? 0);
        $idempotencyKey = $downstreamId > 0
            ? 'zjmf:'.(int) $user->id.':'.$downstreamId
            : 'zjmf:'.(int) $user->id.':'.sha1(json_encode($cartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // 下游在魔方下单时填的主机名与配置项必须随单进入开通环节，
        // 否则客户选的主机名/配置会被静默丢弃，只能按本系统默认值开通。
        $config = $this->buildUpstreamOrderConfig($product, $cartData);

        try {
            $quote = $this->quoteService->quoteForUser(
                $product,
                [
                    'billing_cycle' => $billingCycle,
                    'quantity' => $quantity,
                    'config' => $config,
                ],
                $user,
                [
                    'request_id' => $idempotencyKey,
                    'ip_address' => (string) ($downstream['ip'] ?? ''),
                ]
            );

            $invoice = $this->checkout->create((int) $user->id, [
                'product_id' => $productId,
                'billing_cycle' => $billingCycle,
                'quantity' => $quantity,
                'config' => $config,
                'quote_token' => (string) ($quote['quote_token'] ?? ''),
                'idempotency_key' => $idempotencyKey,
            ], [
                'idempotency_key' => $idempotencyKey,
                'trace_id' => $idempotencyKey,
                'ip_address' => (string) ($downstream['ip'] ?? ''),
            ]);

            $this->recordBinding($user, (int) $invoice->id, $cartData, $downstream);

            return [
                'status' => 200,
                'msg' => '下单成功',
                'data' => [
                    'hostid' => [],
                    'invoiceid' => (int) $invoice->id,
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 结算失败', [
                'user_id' => (int) $user->id,
                'product_id' => $productId,
                'billing_cycle' => $billingCycle,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 400, 'msg' => $exception->getMessage()];
        }
    }

    /**
     * 下游 cart_data → 本地下单 config。
     *
     * 魔方财务在 cart/settle 的 cart_data 里回传：
     *   host          客户填写的主机名
     *   password      客户填写的密码（本地开通不落配置，由开通环节生成/透传，见 ProvisionService）
     *   configoptions 配置项，键为本系统 get_product_config 下发的 options[].id / sub[].id
     * 这里只取主机名与配置项：配置项经 normalizeUpstreamConfigOptions 反查字段名，
     * 匹配不上的会被丢弃（与修复前「全部丢弃」相比只会更准，不会更宽）。
     *
     * @param  array<string, mixed>  $cartData
     * @return array<string, mixed>
     */
    private function buildUpstreamOrderConfig(Product $product, array $cartData): array
    {
        $config = [];

        $hostname = trim((string) ($cartData['host'] ?? ''));
        if ($hostname !== '') {
            $config['hostname'] = $hostname;
        }

        $options = $cartData['configoptions'] ?? $cartData['configoption'] ?? [];
        if (is_array($options) && $options !== []) {
            $config = array_replace($config, $this->checkout->normalizeUpstreamConfigOptions($product, $options));
        }

        // 必须先归一化再同时用于报价与下单：报价凭证按归一化后的配置哈希，
        // 直接传原始值（如整型 2 与归一化后的字符串 '2'）会让哈希对不上，
        // 下单时报「订单配置与报价不一致」。
        return $this->checkout->normalizeConfig($product, $config);
    }

    private function recordBinding(User $user, int $invoiceId, array $cartData, array $downstream): void    {
        $url = trim((string) ($downstream['downstream_url'] ?? ''));
        if ($url === '') {
            return;
        }

        try {
            ZjmfUpstreamBinding::query()->updateOrCreate(
                [
                    'user_id' => (int) $user->id,
                    'invoice_id' => $invoiceId,
                ],
                [
                    'downstream_url' => rtrim($url, '/'),
                    'downstream_token' => (string) ($downstream['downstream_token'] ?? ''),
                    'downstream_id' => (int) ($downstream['downstream_id'] ?? 0),
                    'domain' => (string) ($cartData['host'] ?? ''),
                    'payload' => $cartData,
                ]
            );
        } catch (\Throwable $exception) {
            // 绑定失败不阻断下单主流程，仅记录
            Log::warning('[zjmf-upstream] 下游绑定记录失败', [
                'user_id' => (int) $user->id,
                'invoice_id' => $invoiceId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 供 P6 apply_credit 支付后回填 service_id 用。
     */
    public function bindService(int $invoiceId, int $serviceId): void
    {
        try {
            ZjmfUpstreamBinding::query()
                ->where('invoice_id', $invoiceId)
                ->whereNull('service_id')
                ->update(['service_id' => $serviceId]);
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 服务绑定回填失败', [
                'invoice_id' => $invoiceId,
                'service_id' => $serviceId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
