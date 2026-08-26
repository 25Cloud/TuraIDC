<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\InvoiceService;
use App\Services\Site\SiteProductQuoteService;
use Illuminate\Support\Facades\Log;

/**
 * 上游升降级接口（被魔方财务对接）：/upgrade/*。
 *
 * 魔方财务 Host::upgrade 流程：
 *   - 配置升级：upgrade_config_post（configoption/hid）→ checkout_config_upgrade → 返回 data.invoiceid → apply_credit
 *   - 产品升级：upgrade_product_post（hid/pid/billingcycle）→ checkout_upgrade_product → 返回 data.invoiceid → apply_credit
 *
 * 说明：
 *   - 配置升级为当前唯一真实链路。魔方财务本地已按 percent 差价向用户收款，
 *     上游（TuraIDC）按自身定价算差价生成账单，再由魔方财务 apply_credit 用上游余额支付。
 *   - 产品升级：魔方财务后台对 zjmf_api 产品的「可升级产品」映射被禁用（ID 无法对应），
 *     该功能在魔方财务侧不可用，TuraIDC 侧仅作预留占位，暂不实现。
 */
class UpgradeService
{
    public function __construct(
        private readonly SiteProductQuoteService $quoteService,
        private readonly ServiceDetailService $detailService,
        private readonly InvoiceService $invoiceService,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * 产品升降级预览（/upgrade/upgrade_product_post）。
     *
     * 预留占位：魔方财务后台对 zjmf_api 产品的「可升级产品」映射被禁用，
     * 且产品升级要求目标产品 api_type=zjmf_api && upstream_pid>0（Upgrade.php L1060-1062），
     * ID 无法对应，该链路在魔方财务侧不可用，暂不实现。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function productUpgrade(User $user, array $data): array
    {
        return ['status' => 400, 'msg' => '产品升降级暂未开放'];
    }

    /**
     * 产品升降级结算（/upgrade/checkout_upgrade_product），返回 data.invoiceid。
     *
     * 预留占位：同 productUpgrade，魔方财务侧不可用，暂不实现。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function checkoutProductUpgrade(User $user, array $data): array
    {
        return ['status' => 400, 'msg' => '产品升降级暂未开放'];
    }

    /**
     * 配置项升降级预览（/upgrade/upgrade_config_post）。
     * 校验服务与 configoption，返回差价概览；不落库。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function configUpgrade(User $user, array $data): array
    {
        $context = $this->resolveConfigUpgradeContext($user, $data);
        if (is_string($context)) {
            return ['status' => 400, 'msg' => $context];
        }

        [$service, $product, $config, $oldConfig] = $context;
        $diff = $this->configDiff($product, (string) $service->billing_cycle, $config, $oldConfig);

        return [
            'status' => 200,
            'msg' => '操作成功',
            'data' => [
                'configoption' => $config,
                'old_amount' => number_format($diff['old_amount'], 2, '.', ''),
                'new_amount' => number_format($diff['new_amount'], 2, '.', ''),
                'config_amount' => number_format($diff['diff'], 2, '.', ''),
                'currency' => 'CNY',
            ],
        ];
    }

    /**
     * 配置项升降级结算（/upgrade/checkout_config_upgrade）。
     * 差价 > 0 时创建升级账单返回 invoiceid；差价 <= 0（降级/平级）返回 invoiceid=0，
     * 魔方财务随后 apply_credit 时 TuraIDC 侧按「无需支付」幂等放行。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function checkoutConfigUpgrade(User $user, array $data): array
    {
        $context = $this->resolveConfigUpgradeContext($user, $data);
        if (is_string($context)) {
            return ['status' => 400, 'msg' => $context];
        }

        [$service, $product, $config, $oldConfig] = $context;
        $diff = $this->configDiff($product, (string) $service->billing_cycle, $config, $oldConfig);
        $serviceId = (int) $service->id;
        $configOption = is_array($data['configoption'] ?? null) ? $data['configoption'] : [];
        $configHash = hash('sha256', (string) json_encode($configOption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($diff['diff'] <= 0) {
            // 降级/平级无账单：魔方财务支付后 doUpgrade 即生效，上游同步落库，
            // 使下次 upgrade_config_post 的差价基于降级后的配置。
            $this->persistConfigSnapshot($service, $config);

            return [
                'status' => 200,
                'msg' => '无需支付',
                'data' => [
                    'invoiceid' => 0,
                    'config_amount' => number_format($diff['diff'], 2, '.', ''),
                ],
            ];
        }

        try {
            $invoice = $this->createConfigUpgradeInvoice($user, $service, $product, $config, $oldConfig, $diff['diff'], $configHash, $configOption);

            return [
                'status' => 200,
                'msg' => '操作成功',
                'data' => [
                    'invoiceid' => (int) $invoice->id,
                    'config_amount' => number_format($diff['diff'], 2, '.', ''),
                ],
            ];
        } catch (\Throwable $exception) {
            return $this->failure($user, $serviceId, 'checkout_config_upgrade', $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{Service, Product, array<string, mixed>, array<string, mixed>}|string 错误时返回 string 消息
     */
    private function resolveConfigUpgradeContext(User $user, array $data): array|string
    {
        $serviceId = (int) ($data['hid'] ?? 0);
        $configOption = is_array($data['configoption'] ?? null) ? $data['configoption'] : [];

        if ($serviceId <= 0 || $configOption === []) {
            return '参数错误：需要 hid、configoption';
        }

        try {
            $service = $this->detailService->findUserService($user, $serviceId, ['product']);
        } catch (\Throwable $exception) {
            return '服务不存在';
        }
        if (! $service->product instanceof Product) {
            return '服务未关联商品';
        }

        $config = $this->buildConfigFromConfigOption($service->product, $configOption);
        if ($config === null) {
            return '配置项参数无效';
        }

        return [$service, $service->product, $config, $this->extractOldConfig($service)];
    }

    /**
     * 差价计算：新配置周期总价 - 旧配置周期总价（TuraIDC 自身定价，无代理折扣）。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $oldConfig
     * @return array{old_amount:float, new_amount:float, diff:float}
     */
    private function configDiff(Product $product, string $billingCycle, array $config, array $oldConfig): array
    {
        $oldAmount = (float) ($this->quoteService->quote($product, [
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $oldConfig,
        ], [])['total_amount'] ?? 0);

        $newAmount = (float) ($this->quoteService->quote($product, [
            'billing_cycle' => $billingCycle,
            'quantity' => 1,
            'config' => $config,
        ], [])['total_amount'] ?? 0);

        return [
            'old_amount' => round($oldAmount, 2),
            'new_amount' => round($newAmount, 2),
            'diff' => round($newAmount - $oldAmount, 2),
        ];
    }

    /**
     * configoption（{TuraIDC 配置项 id => qty 或子项 id}）→ TuraIDC config（{field => value}）。
     * 键/值与魔方财务同步配置项时的 upstream_id 一一对应；无法识别的配置项返回 null。
     *
     * @param  array<string, mixed>  $configOption
     * @return array<string, mixed>|null
     */
    private function buildConfigFromConfigOption(Product $product, array $configOption): ?array
    {
        $config = [];
        $items = (array) ($product->config_options ?? []);

        foreach ($configOption as $configId => $value) {
            $configId = (int) $configId;
            $matched = null;
            foreach ($items as $item) {
                $item = (array) $item;
                if ((int) ($item['id'] ?? 0) === $configId) {
                    $matched = $item;
                    break;
                }
            }

            if ($matched === null) {
                return null;
            }

            $field = $this->parseField($matched);
            if ($field === '' || $value === null || $value === '') {
                return null;
            }

            $config[$field] = (string) $value;
        }

        return $config;
    }

    /**
     * 旧配置：优先取下单账单 config_snapshot（normalizedConfig 形式）。
     *
     * @return array<string, mixed>
     */
    private function extractOldConfig(Service $service): array
    {
        $invoice = $service->invoice;
        if (! $invoice instanceof Invoice) {
            return [];
        }

        $snapshot = $invoice->config_snapshot;

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * 把新配置写回服务主账单 config_snapshot（保留产品路径展示键）。
     *
     * @param  array<string, mixed>  $config
     */
    private function persistConfigSnapshot(Service $service, array $config): void
    {
        $invoice = $service->invoice;
        if (! $invoice instanceof Invoice) {
            return;
        }

        $current = is_array($invoice->config_snapshot ?? null) ? $invoice->config_snapshot : [];
        $kept = array_intersect_key($current, array_flip([
            'product_full_path', 'product_path_segments',
            'first_product_group_name', 'second_product_group_name', 'third_product_group_name',
        ]));

        $invoice->update(['config_snapshot' => array_merge($config, $kept)]);
    }

    /**
     * 创建配置升级账单（幂等：同服务同配置的未付升级账单直接复用）。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $oldConfig
     * @param  array<string, mixed>  $configOption
     */
    private function createConfigUpgradeInvoice(
        User $user,
        Service $service,
        Product $product,
        array $config,
        array $oldConfig,
        float $amount,
        string $configHash,
        array $configOption,
    ): Invoice {
        $existing = Invoice::query()
            ->where('user_id', (int) $service->user_id)
            ->where('service_id', (int) $service->id)
            ->where('type', InvoiceType::UPGRADE)
            ->where('status', InvoiceStatus::UNPAID)
            ->latest('id')
            ->first();

        if ($existing instanceof Invoice) {
            $meta = is_array($existing->config_pricing_snapshot ?? null)
                ? ($existing->config_pricing_snapshot['meta'] ?? [])
                : [];
            if (($meta['kind'] ?? '') === 'config_upgrade' && ($meta['configoption_hash'] ?? '') === $configHash) {
                return $existing;
            }

            $this->checkout->cancel($existing, [
                'actor_type' => 'system',
                'actor_user_id' => (int) $user->id,
                'actor_name' => (string) ($user->email ?? 'system'),
                'reason' => 'config_upgrade_invoice_replaced',
            ]);
        }

        $traceId = 'zjmf:'.(int) $user->id.':'.(int) $service->id.':config_upgrade';
        $amountFormatted = number_format($amount, 2, '.', '');

        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $service->user_id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => $this->productDisplayName($product),
            'product_type_snapshot' => (string) $product->product_type,
            'service_id' => (int) $service->id,
            'type' => InvoiceType::UPGRADE,
            'amount' => $amountFormatted,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => (string) $service->billing_cycle,
            'quantity' => 1,
            'config_snapshot' => [
                'config' => $config,
                'old_config' => $oldConfig,
            ],
            'config_pricing_snapshot' => [
                'base_amount' => '0.00',
                'config_amount' => $amountFormatted,
                'setup_fee' => '0.00',
                'items' => [[
                    'field' => 'config_upgrade',
                    'label' => '配置升降级',
                    'value_label' => $this->configDisplayLabel($config),
                    'amount' => $amountFormatted,
                ]],
                'meta' => [
                    'kind' => 'config_upgrade',
                    'mode' => 'host_config_upgrade',
                    'configoption_hash' => $configHash,
                    'config' => $config,
                    'old_config' => $oldConfig,
                    'configoption' => $configOption,
                ],
            ],
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
            'trace_id' => $traceId,
        ]);

        $this->invoiceService->syncProjection($invoice);

        Log::info('[zjmf-upstream] 配置升级账单已创建', [
            'user_id' => (int) $user->id,
            'service_id' => (int) $service->id,
            'invoice_id' => (int) $invoice->id,
            'amount' => $amountFormatted,
        ]);

        return $invoice;
    }

    /**
     * 解析配置项字段名（对齐 HandlesOrderCalculation::parseField）。
     *
     * @param  array<string, mixed>  $item
     */
    private function parseField(array $item): string
    {
        $field = trim((string) ($item['field'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $type = (int) ($item['option_type'] ?? -1);
        $map = [
            4 => 'ip_num',
            5 => 'os',
            6 => 'cpu',
            7 => 'cpu',
            8 => 'memory',
            9 => 'memory',
            10 => 'bw',
            11 => 'bw',
            12 => 'area',
            13 => 'system_disk_size',
            14 => 'system_disk_size',
            16 => 'cpu',
            17 => 'memory',
            18 => 'bw',
            19 => 'system_disk_size',
        ];
        if (isset($map[$type])) {
            return $map[$type];
        }

        $source = (string) ($item['option_name'] ?? $item['spec_key'] ?? '');
        $parts = explode('|', $source);

        return trim((string) $parts[0]);
    }

    /**
     * 配置显示名（对齐 SiteProductQuoteService 的 product_spec_display 语义，简化取商品名）。
     */
    private function productDisplayName(Product $product): string
    {
        $custom = trim((string) ($product->custom_display_name ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return '商品 #'.$product->id;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configDisplayLabel(array $config): string
    {
        if ($config === []) {
            return '配置变更';
        }

        return implode(' / ', array_map(
            static fn (string $key, mixed $value): string => $key.'='.(string) $value,
            array_keys($config),
            array_values($config)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(User $user, int $serviceId, string $action, \Throwable $exception): array
    {
        Log::warning('[zjmf-upstream] 升降级失败', [
            'user_id' => (int) $user->id,
            'service_id' => $serviceId,
            'action' => $action,
            'error' => $exception->getMessage(),
        ]);

        return ['status' => 400, 'msg' => (string) $exception->getMessage()];
    }
}
