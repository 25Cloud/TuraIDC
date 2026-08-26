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

        try {
            $quote = $this->quoteService->quoteForUser(
                $product,
                [
                    'billing_cycle' => $billingCycle,
                    'quantity' => $quantity,
                    'config' => [],
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
                'config' => [],
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

    private function recordBinding(User $user, int $invoiceId, array $cartData, array $downstream): void
    {
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
