<?php

declare(strict_types=1);

namespace App\Services\Order\Concerns;

use App\Constants\BillingCycle;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Support\Money;
use App\Support\ProductConfigOptionPresenter;
use Illuminate\Support\Str;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesUpstreamQuoting;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\DB;

trait HandlesOrderCalculation
{
    // ────────── 配置类型常量（OrderService / CheckoutService 共用） ──────────

    /** 范围选择类型（ip_num / memory / bw / cpu / disk）—— 支持 min/max 区间 */
    private const RANGE_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    /** OS 选择类型 —— 不参与数值计算 */
    private const OS_TYPES = [5];

    /** 计费周期 → 月数映射，取自 {@see BillingCycle} */
    private const BILLING_CYCLE_MONTHS = BillingCycle::RENEWABLE_MONTHS;

    /** 配置项 type → 字段名映射 */
    private const TYPE_FIELD_MAP = [
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

    public function calculateAmount(Product $product, string $billingCycle, array $config = []): float
    {
        $config = $this->normalizeConfig($product, $config);
        $baseAmount = (float) $product->getPriceByBillingCycle($billingCycle);
        if ($baseAmount <= 0) {
            return 0;
        }

        $quote = $this->buildQuoteBreakdown($product, $billingCycle, $config);

        $upstreamQuote = $this->resolveUpstreamConfigQuote($product, $config, $billingCycle);
        if ($upstreamQuote !== null) {
            $quote['config_amount'] = (float) ($upstreamQuote['config_amount'] ?? 0);
            $quote['items'] = $this->mapUpstreamQuoteItems((array) ($upstreamQuote['items'] ?? []));
        }

        $setupFee = (float) $product->setup_fee;

        return Money::add($baseAmount, $quote['config_amount'] ?? 0, $setupFee);
    }

    public function quote(Product $product, string $billingCycle, array $config = [], int $quantity = 1): array
    {
        $config = $this->normalizeConfig($product, $config);
        $baseAmount = (float) $product->getPriceByBillingCycle($billingCycle);
        $quantity = max($quantity, 1);

        throw_if($baseAmount <= 0, new BusinessException('无效的计费周期'));

        $quote = $this->buildQuoteBreakdown($product, $billingCycle, $config);
        $upstreamQuote = $this->resolveUpstreamConfigQuote($product, $config, $billingCycle);
        if ($upstreamQuote !== null) {
            $quote['config_amount'] = (float) ($upstreamQuote['config_amount'] ?? 0);
            $quote['items'] = $this->mapUpstreamQuoteItems((array) ($upstreamQuote['items'] ?? []));
        }
        $setupFee = (float) $product->setup_fee;
        $unitTotalAmount = Money::add($baseAmount, $quote['config_amount'] ?? 0, $setupFee);
        $scaledBaseAmount = Money::multiply($baseAmount, $quantity);
        $scaledConfigAmount = Money::multiply($quote['config_amount'] ?? 0, $quantity);
        $scaledSetupFee = Money::multiply($setupFee, $quantity);
        $totalAmount = Money::multiply($unitTotalAmount, $quantity);
        $scaledItems = collect($quote['items'])
            ->map(function (array $item) use ($quantity) {
                return [
                    ...$item,
                    'amount' => $this->formatAmount(Money::multiply($item['amount'] ?? 0, $quantity)),
                ];
            })
            ->values()
            ->all();

        return [
            'product_id' => (int) $product->id,
            'billing_cycle' => $billingCycle,
            'quantity' => $quantity,
            'unit_base_amount' => $this->formatAmount($baseAmount),
            'unit_config_amount' => $this->formatAmount((float) $quote['config_amount']),
            'unit_setup_fee' => $this->formatAmount($setupFee),
            'unit_total_amount' => $this->formatAmount($unitTotalAmount),
            'base_amount' => $this->formatAmount($scaledBaseAmount),
            'config_amount' => $this->formatAmount($scaledConfigAmount),
            'setup_fee' => $this->formatAmount($scaledSetupFee),
            'total_amount' => $this->formatAmount($totalAmount),
            'items' => $scaledItems,
        ];
    }

    public function buildConfigPricingSnapshot(Product $product, string $billingCycle, array $config = [], int $quantity = 1): array
    {
        $config = $this->normalizeConfig($product, $config);
        $baseAmount = (float) $product->getPriceByBillingCycle($billingCycle);
        $quote = $this->buildConfigPricingBreakdown($product, $billingCycle, $config);
        $upstreamQuote = $this->resolveUpstreamConfigQuote($product, $config, $billingCycle);
        if ($upstreamQuote !== null) {
            $quote['config_amount'] = (float) ($upstreamQuote['config_amount'] ?? 0);
            $quote['items'] = $this->mapUpstreamQuoteItems((array) ($upstreamQuote['items'] ?? []));
        }
        $setupFee = (float) $product->setup_fee;
        $quantity = max($quantity, 1);
        $unitTotalAmount = Money::add($baseAmount, $quote['config_amount'] ?? 0, $setupFee);
        $scaledItems = collect($quote['items'])
            ->map(function (array $item) use ($quantity) {
                return [
                    ...$item,
                    'amount' => $this->formatAmount(Money::multiply($item['amount'] ?? 0, $quantity)),
                ];
            })
            ->values()
            ->all();

        return [
            'quantity' => $quantity,
            'unit_base_amount' => $this->formatAmount($baseAmount),
            'unit_config_amount' => $this->formatAmount((float) $quote['config_amount']),
            'unit_setup_fee' => $this->formatAmount($setupFee),
            'unit_total_amount' => $this->formatAmount($unitTotalAmount),
            'base_amount' => $this->formatAmount(Money::multiply($baseAmount, $quantity)),
            'config_amount' => $this->formatAmount(Money::multiply($quote['config_amount'] ?? 0, $quantity)),
            'setup_fee' => $this->formatAmount(Money::multiply($setupFee, $quantity)),
            'total_amount' => $this->formatAmount($unitTotalAmount * $quantity),
            'items' => $scaledItems,
        ];
    }

    public function normalizeConfig(Product $product, array $config = []): array
    {
        $normalized = [];

        // 存量商品的 config_options 缺 option_mode（提示项仍标成 select），
        // 直接按库值判断会让「明确禁止」这类提示项被当真实规格写进快照，
        // 覆盖同名的真实配置。读取时先补齐呈现类型。
        foreach (ProductConfigOptionPresenter::present((array) ($product->config_options ?? [])) as $item) {
            if ((int) ($item['hidden'] ?? 0) === 1) {
                continue;
            }

            // 提示型配置项（如「明确禁止」）只用于前台展示购买须知，
            // 不参与计价、也不写入快照，否则会覆盖同名的真实规格配置。
            if ($this->isTextOnlyConfigOption($item)) {
                continue;
            }

            $field = $this->parseField($item);
            if ($field === '' || ! array_key_exists($field, $config)) {
                continue;
            }

            $value = $this->normalizeConfigValue($item, $config[$field]);
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$field] = $value;
        }

        if (array_key_exists('hostname', $config)) {
            $hostname = $this->normalizeHostname((string) $config['hostname']);
            if ($hostname !== '') {
                $normalized['hostname'] = $hostname;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * 把下游（魔方财务）回传的 configoption 还原成本地 config 结构。
     *
     * 魔方财务把本系统 get_product_config 下发的 `options[].id` / `sub[].id` 作为
     * upstream_id 落库，下单时以 `configoption[<option id>] = 值或数量` 回传：
     * 数量型回传数量，单选型回传所选子项的 sub id。单选型这里把 sub id
     * 反查回子项真实值（option_name_first 优先），保证 config 落的是业务值
     * 而非内部 id；反查不到时保留原值（与直接提交值的行为一致）。
     * 无法匹配的项直接丢弃——normalizeConfig() 会把不合法取值归一为 null
     * 并跳过，因此最坏情况退化为「忽略该配置项」，不会打断下单。
     *
     * @param  array<string|int, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function normalizeUpstreamConfigOptions(Product $product, array $submitted): array
    {
        if ($submitted === []) {
            return [];
        }

        $submittedByKey = [];
        foreach ($submitted as $key => $value) {
            $submittedByKey[(string) $key] = $value;
        }

        $config = [];

        // 同 normalizeConfig：存量数据需先补齐呈现类型，提示项才不会被当规格回传。
        foreach (ProductConfigOptionPresenter::present((array) ($product->config_options ?? [])) as $item) {
            if ((int) ($item['hidden'] ?? 0) === 1) {
                continue;
            }

            if ($this->isTextOnlyConfigOption($item)) {
                continue;
            }

            $field = $this->parseField($item);
            if ($field === '') {
                continue;
            }

            $optionId = (int) ($item['id'] ?? 0);
            if ($optionId > 0 && array_key_exists((string) $optionId, $submittedByKey)) {
                $config[$field] = $submittedByKey[(string) $optionId];
            } else {
                // 单选/多选型：下游也可能直接以子项 upstream id 作为键回传
                $matched = false;
                foreach ((array) ($item['sub'] ?? []) as $sub) {
                    if (! is_array($sub)) {
                        continue;
                    }

                    $subId = (int) ($sub['id'] ?? 0);
                    if ($subId > 0 && array_key_exists((string) $subId, $submittedByKey)) {
                        $config[$field] = $submittedByKey[(string) $subId];
                        $matched = true;

                        break;
                    }
                }

                if (! $matched) {
                    continue;
                }
            }

            $type = (int) ($item['option_type'] ?? -1);
            $isRange = in_array($type, self::RANGE_TYPES, true)
                || trim((string) ($item['option_mode'] ?? '')) === 'range';
            if (! $isRange) {
                $config[$field] = $this->resolveSubValue($item, $config[$field]);
            }
        }

        return $config;
    }

    /**
     * 单选型取值还原：下游回传的是 sub id 时反查子项真实值
     * （option_name_first 优先，回退 option_name / id）。
     */
    private function resolveSubValue(array $item, mixed $value): mixed
    {
        $candidate = trim((string) $value);
        if ($candidate === '' || ! preg_match('/^\d+$/', $candidate)) {
            return $value;
        }

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            if (! is_array($sub)) {
                continue;
            }

            if ((int) ($sub['id'] ?? 0) !== (int) $candidate) {
                continue;
            }

            $resolved = trim((string) ($sub['option_name_first'] ?? ''));
            if ($resolved === '') {
                $resolved = trim((string) ($sub['option_name'] ?? ''));
            }

            return $resolved !== '' ? $resolved : $value;
        }

        return $value;
    }

    private function calculateConfigExtra(Product $product, string $billingCycle, array $config): float
    {
        return (float) $this->buildQuoteBreakdown($product, $billingCycle, $config)['config_amount'];
    }

    private function buildConfigPricingBreakdown(Product $product, string $billingCycle, array $config): array
    {
        $extraAmount = 0.0;
        $items = [];

        foreach ((array) ($product->config_options ?? []) as $item) {
            if ((int) ($item['hidden'] ?? 0) === 1) {
                continue;
            }

            $field = $this->parseField($item);
            if ($field === '' || ! array_key_exists($field, $config)) {
                continue;
            }

            $type = (int) ($item['option_type'] ?? -1);
            $isRange = in_array($type, self::RANGE_TYPES, true)
                || trim((string) ($item['option_mode'] ?? '')) === 'range';
            $selectedValue = (string) $config[$field];
            $amount = 0.0;

            if (! in_array($type, self::OS_TYPES, true) && $field !== 'os') {
                $amount = $isRange
                    ? (float) ($this->calculateRangeOptionExtraDetail($item, $billingCycle, $config, $field)['amount'] ?? 0)
                    : $this->findSelectedOptionPrice($item, $selectedValue, $billingCycle);
            }

            $extraAmount += $amount;
            $items[] = [
                'field' => $field,
                'label' => $this->resolveConfigLabel($item, $field),
                'value' => $selectedValue,
                'value_label' => $this->resolveConfigSnapshotValueLabel($item, $field, $selectedValue, $isRange),
                'amount' => $this->formatAmount($amount),
            ];
        }

        if (array_key_exists('hostname', $config)) {
            $hostname = trim((string) ($config['hostname'] ?? ''));
            if ($hostname !== '') {
                $items[] = [
                    'field' => 'hostname',
                    'label' => '主机名',
                    'value' => $hostname,
                    'value_label' => $hostname,
                    'amount' => $this->formatAmount(0),
                ];
            }
        }

        return [
            'config_amount' => round($extraAmount, 2),
            'items' => $items,
        ];
    }

    /**
     * 商品来自上游且插件支持实时报价时，用上游 /quote 的计算结果覆盖本地配置加价。
     *
     * 转售商品本地 config_options 通常没有单价（上游不通过目录接口暴露），本地
     * buildQuoteBreakdown 算出来是 0；上游 /quote 才是真实价格来源（如数据盘 1 元/GB）。
     * 这里只覆盖 config_amount 与 items，base 仍用商家在 product.pricing 设的转售价，
     * 既保留商家加价、又让加价项显示真实价格。按 quantity=1 取单价，交给上层按数量缩放。
     */
    private function resolveUpstreamConfigQuote(Product $product, array $config, string $billingCycle): ?array
    {
        if ($config === []) {
            return null;
        }

        $binding = DB::table('product_upstream_bindings')
            ->where('product_id', (int) $product->id)
            ->first();

        if ($binding === null || (int) ($binding->upstream_product_id ?? 0) <= 0) {
            return null;
        }

        try {
            $provider = app(ProviderResolver::class)->resolveForProduct($product);
        } catch (\Throwable $exception) {
            return null;
        }

        if (! $provider->supports(ProvidesUpstreamQuoting::class)) {
            return null;
        }

        $capability = $provider->maybe(ProvidesUpstreamQuoting::class);

        if ($capability === null) {
            return null;
        }

        // product_upstream_bindings 只持 supplier_plugin_binding_id，需再连 supplier_plugin_bindings 取 supplier_id
        $pluginBinding = DB::table('supplier_plugin_bindings')
            ->where('id', (int) ($binding->supplier_plugin_binding_id ?? 0))
            ->first();

        if ($pluginBinding === null) {
            return null;
        }

        $rawSupplier = Supplier::find((int) ($pluginBinding->supplier_id ?? 0));

        if ($rawSupplier === null) {
            return null;
        }

        // 供应商主表无 api_url/api_key 列，开放接口地址与密钥存于 supplier_plugin_bindings；
        // 须经绑定解析器注入运行时凭证（base_url -> api_url），否则 quoteUpstreamProduct 读到空地址。
        // 解析过程涉及查库与解密，异常时降级到后台定价兜底，不让整个报价流程失败。
        try {
            $supplier = app(\App\Services\Integrations\Plugins\PluginBindingResolver::class)
                ->supplierWithRuntimeCredentials($rawSupplier);
        } catch (\Throwable $exception) {
            return $this->buildFallbackConfigQuote($product, $config, $billingCycle);
        }

        try {
            $data = $capability->quoteUpstreamProduct($supplier, (int) $binding->upstream_product_id, $config, $billingCycle, 1);
        } catch (\Throwable $exception) {
            $data = null;
        }

        $data = is_array($data) ? $data : [];

        // 上游有返回：按配置项 markup 套加价倍率/固定价
        if ($data !== []) {
            return $this->applyConfigOptionMarkup($product, $data, $config, $billingCycle);
        }

        // 上游异常/空返回：若后台为配置项设了固定定价则用后台定价兜底，避免前台显示 0 元
        return $this->buildFallbackConfigQuote($product, $config, $billingCycle);
    }

    /**
     * 读取配置项上的加价配置 markup。
     *
     * 商家可在后台为每个配置项设置加价方式，实现「上游成本 + 商家利润」：
     *   - 跟随上游（未配置 markup / enabled=false）：原样使用上游报价
     *   - multiplier：上游价 × 倍率，上游调价自动跟随
     *   - fixed：直接用商家填的单价（每 GB / 每步）定价，不依赖上游返回值
     *   - both：上游价 × 倍率 + 固定附加费
     *
     * @return array{enabled: bool, mode: string, multiplier: float, unit_price: float, extra_amount: float}
     */
    private function resolveConfigOptionMarkup(array $item): array
    {
        $markup = is_array($item['markup'] ?? null) ? $item['markup'] : [];
        $enabled = (bool) ($markup['enabled'] ?? false);
        $mode = (string) ($markup['mode'] ?? 'inherit');

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'multiplier' => round((float) ($markup['multiplier'] ?? 1), 4),
            'unit_price' => round((float) ($markup['unit_price'] ?? 0), 2),
            'extra_amount' => round((float) ($markup['extra_amount'] ?? 0), 2),
        ];
    }

    /**
     * 对上游报价的每个 item 套用对应配置项的加价配置。
     * 未配置 markup 的项原样保留；fixed 模式用商家单价重算该项金额。
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyConfigOptionMarkup(Product $product, array $data, array $config, string $billingCycle): array
    {
        $markupByField = [];

        foreach ((array) ($product->config_options ?? []) as $option) {
            if ((int) ($option['hidden'] ?? 0) === 1) {
                continue;
            }

            $field = $this->parseField($option);

            if ($field === '') {
                continue;
            }

            $markupByField[$field] = [
                'option' => $option,
                'markup' => $this->resolveConfigOptionMarkup((array) $option),
            ];
        }

        $items = [];
        $configAmount = 0.0;
        $seenFields = [];

        foreach ((array) ($data['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = (string) ($item['field'] ?? '');

            if ($field === '') {
                continue;
            }

            $seenFields[$field] = true;
            // 上游若返回负数金额，钳制为 0，避免污染合计
            $amount = Money::round(max((float) ($item['amount'] ?? 0), 0));
            $entry = $markupByField[$field] ?? null;

            if ($entry !== null) {
                $markup = $entry['markup'];
                $amount = $this->applyMarkupToAmount($amount, $markup, $config, $field, $entry['option'], $billingCycle);
            }

            $configAmount = Money::add($configAmount, $amount);

            $items[] = [
                'field' => $field,
                'label' => (string) ($item['label'] ?? $field),
                'amount' => (string) $amount,
            ];
        }

        // 上游未返回的字段：若商家显式配置了 fixed 单价，则补上该项，
        // 保证「自定义单价」不依赖上游是否把该字段计入报价。
        foreach ($markupByField as $field => $entry) {
            if (isset($seenFields[$field])) {
                continue;
            }

            // 用户未选中该字段、或配置项已隐藏时不补项
            if (! $this->isConfigOptionChargeable($entry['option'], $config, (string) $field)) {
                continue;
            }

            $markup = $entry['markup'];

            if (! $markup['enabled'] || $markup['unit_price'] <= 0) {
                continue;
            }

            if (! in_array($markup['mode'], ['fixed', 'both'], true)) {
                continue;
            }

            $amount = $this->applyMarkupToAmount(0.0, $markup, $config, (string) $field, $entry['option'], $billingCycle);

            if ($amount <= 0) {
                continue;
            }

            $configAmount = Money::add($configAmount, $amount);

            $items[] = [
                'field' => (string) $field,
                'label' => (string) ($entry['option']['name'] ?? $field),
                'amount' => (string) $amount,
            ];
        }

        $data['items'] = $items;

        if ($items !== []) {
            // 有可计费项：用重算后的合计覆盖上游原值
            $data['config_amount'] = Money::round($configAmount);
        } else {
            // 上游没给任何可计费项：原值直接透传，若为负数则钳制为 0，
            // 避免负数加价进入总价
            $data['config_amount'] = Money::round(max((float) ($data['config_amount'] ?? 0), 0));
        }

        return $data;
    }

    /**
     * 按加价配置计算某一项的最终金额。
     *
     * 各模式只读取自己语义内的字段，互不影响：
     *   - inherit   : 不加价，原样返回上游价
     *   - multiplier: 上游价 × 倍率 + 附加费（忽略残留的 unit_price）
     *   - fixed     : 自定义单价 × 数量 + 附加费（忽略残留的 multiplier）
     *   - both      : 自定义单价 × 数量 × 倍率 + 附加费
     *
     * 必须按 mode 显式分支，不能只看「字段是否 > 0」：后台切换模式时只是隐藏
     * 对应输入框，已存值不会被清空，按字段判断会把上一个模式的残留值算进去。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $option
     */
    private function applyMarkupToAmount(float $upstreamAmount, array $markup, array $config, string $field, array $option, string $billingCycle): float
    {
        if (! $markup['enabled'] || $markup['mode'] === 'inherit') {
            return Money::round(max($upstreamAmount, 0));
        }

        $mode = $markup['mode'];
        $extra = $markup['extra_amount'];

        // fixed / both：商家自定义单价为基准，完全自己定价
        if ($mode === 'fixed' || $mode === 'both') {
            $unitPrice = $markup['unit_price'];

            if ($unitPrice <= 0) {
                // 未填单价时退化为上游成本，避免出现 0 元加价
                return Money::round(max(Money::add(max($upstreamAmount, 0), $extra), 0));
            }

            $quantity = $this->resolveMarkupChargeQuantity($config, $field, (array) $option);
            $base = Money::multiply($unitPrice, $quantity);

            if ($mode === 'both') {
                $multiplier = $markup['multiplier'] > 0 ? $markup['multiplier'] : 1;
                $base = Money::multiply($base, $multiplier);
            }

            return Money::round(max(Money::add($base, $extra), 0));
        }

        // multiplier：以上游价为基准
        $multiplier = $markup['multiplier'] > 0 ? $markup['multiplier'] : 1;

        return Money::round(max(Money::add(Money::multiply(max($upstreamAmount, 0), $multiplier), $extra), 0));
    }

    /**
     * 计算自定义单价的计费数量。
     *
     * range 型配置项按数量计费，口径与 calculateRangeChargeSteps 一致；
     * select 型选中值是选项 ID（如 "13632459"）而非数量，计费数量固定为 1，
     * 否则把 ID 当整数会算出天文数字般的金额。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $option
     */
    private function resolveMarkupChargeQuantity(array $config, string $field, array $option): int
    {
        if (! $this->isRangeConfigOption($option)) {
            return 1;
        }

        return $this->resolveRangeStepsForPricing(
            $this->resolveSelectedConfigValue($config, $field, $option),
            $option
        );
    }

    /**
     * 判断配置项是否为数量范围型。
     *
     * @param  array<string, mixed>  $option
     */
    private function isRangeConfigOption(array $option): bool
    {
        $mode = trim((string) ($option['option_mode'] ?? ''));

        if ($mode !== '') {
            return $mode === 'range';
        }

        return in_array((int) ($option['option_type'] ?? -1), self::RANGE_TYPES, true);
    }

    /**
     * 配置项是否应对本次报价生效：未隐藏，且本次提交确实选中了该字段。
     *
     * 隐藏项与未选中项都不应产生加价，否则会出现「用户没选却付费」。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $option
     */
    private function isConfigOptionChargeable(array $option, array $config, string $field): bool
    {
        if ((int) ($option['hidden'] ?? 0) === 1) {
            return false;
        }

        if (array_key_exists('hidden', $option) && (bool) $option['hidden'] === true) {
            return false;
        }

        return array_key_exists($field, $config);
    }

    /**
     * 取配置项当前选中值（range 取数量，select 取原值）。
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $option
     */
    private function resolveSelectedConfigValue(array $config, string $field, array $option): int
    {
        $raw = $config[$field] ?? null;

        if ($raw === null || $raw === '') {
            return (int) ($option['qty_minimum'] ?? 0);
        }

        return max((int) $raw, 0);
    }

    /**
     * 计算 range 型配置项的计费步数（与 calculateRangeChargeSteps 口径一致）。
     *
     * @param  array<string, mixed>  $option
     */
    private function resolveRangeStepsForPricing(int $value, array $option): int
    {
        $rangeMin = (int) ($option['qty_minimum'] ?? 0);
        $rangeStep = max((int) ($option['qty_stage'] ?? 1), 1);

        if ($value <= 0) {
            return 0;
        }

        if ($rangeMin <= 0) {
            return (int) ceil($value / $rangeStep);
        }

        return (int) floor(max($value - $rangeMin, 0) / $rangeStep) + 1;
    }

    /**
     * 上游报价不可用时的兜底：用后台为配置项设置的固定定价合成 config_amount / items。
     * 仅对显式配置了 fixed/both 且填了单价的项生效，其余项不出现（保持 0 元）。
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function buildFallbackConfigQuote(Product $product, array $config, string $billingCycle): ?array
    {
        $items = [];
        $configAmount = 0.0;

        foreach ((array) ($product->config_options ?? []) as $option) {
            if ((int) ($option['hidden'] ?? 0) === 1) {
                continue;
            }

            $field = $this->parseField($option);

            if ($field === '') {
                continue;
            }

            // 用户未选中该字段时不应计费
            if (! array_key_exists($field, $config)) {
                continue;
            }

            $markup = $this->resolveConfigOptionMarkup((array) $option);

            if (! $markup['enabled'] || $markup['unit_price'] <= 0) {
                continue;
            }

            if (! in_array($markup['mode'], ['fixed', 'both'], true)) {
                continue;
            }

            $amount = $this->applyMarkupToAmount(0.0, $markup, $config, $field, (array) $option, $billingCycle);

            if ($amount <= 0) {
                continue;
            }

            $configAmount = Money::add($configAmount, $amount);
            $items[] = [
                'field' => $field,
                'label' => (string) ($option['name'] ?? $field),
                'amount' => (string) $amount,
            ];
        }

        if ($items === []) {
            return null;
        }

        return [
            'config_amount' => round($configAmount, 2),
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{field: string, label: string, amount: string}>
     */
    private function mapUpstreamQuoteItems(array $items): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = (string) ($item['field'] ?? '');

            if ($field === '') {
                continue;
            }

            $mapped[] = [
                'field' => $field,
                'label' => (string) ($item['label'] ?? $field),
                'amount' => (string) ($item['amount'] ?? '0.00'),
            ];
        }

        return $mapped;
    }

    private function buildQuoteBreakdown(Product $product, string $billingCycle, array $config): array
    {
        $extraAmount = 0.0;
        $items = [];

        foreach ((array) ($product->config_options ?? []) as $item) {
            if ((int) ($item['hidden'] ?? 0) === 1) {
                continue;
            }

            $type = (int) ($item['option_type'] ?? -1);
            $field = $this->parseField($item);

            if ($field === '' || in_array($type, self::OS_TYPES, true) || $field === 'os') {
                continue;
            }

            // 支持 option_mode='range' 作为范围型判断（自定义配置项格式）
            $isRange = in_array($type, self::RANGE_TYPES, true)
                || trim((string) ($item['option_mode'] ?? '')) === 'range';

            if ($isRange) {
                $detail = $this->calculateRangeOptionExtraDetail($item, $billingCycle, $config, $field);
                if ($detail['amount'] > 0) {
                    $extraAmount += $detail['amount'];
                    $items[] = [
                        'field' => $field,
                        'label' => $this->resolveConfigLabel($item, $field),
                        'amount' => $this->formatAmount($detail['amount']),
                    ];
                }

                continue;
            }

            $selected = $config[$field] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }

            $amount = $this->findSelectedOptionPrice($item, (string) $selected, $billingCycle);
            if ($amount > 0) {
                $extraAmount += $amount;
                $items[] = [
                    'field' => $field,
                    'label' => $this->resolveConfigLabel($item, $field),
                    'amount' => $this->formatAmount($amount),
                ];
            }
        }

        return [
            'config_amount' => round($extraAmount, 2),
            'items' => $items,
        ];
    }

    private function calculateRangeOptionExtra(array $item, string $billingCycle, array $config, string $field): float
    {
        return (float) $this->calculateRangeOptionExtraDetail($item, $billingCycle, $config, $field)['amount'];
    }

    private function calculateRangeOptionExtraDetail(array $item, string $billingCycle, array $config, string $field): array
    {
        // 与计价明细口径一致：配置中未提交该项时按 0 元计费，
        // 避免账单金额按 qty_minimum 收费而明细却跳过该项导致两边对不上。
        if (! array_key_exists($field, $config)) {
            return [
                'amount' => 0.0,
                'selected_value' => (int) ($item['qty_minimum'] ?? 0),
            ];
        }

        $rangeMin = (int) ($item['qty_minimum'] ?? 0);
        $rangeStep = max((int) ($item['qty_stage'] ?? 1), 1);
        $value = max((int) ($config[$field] ?? $rangeMin), $rangeMin);
        $visibleSubCount = 0;

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            if ((int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }

            $visibleSubCount++;

            $subMin = (int) ($sub['qty_minimum'] ?? 0);
            $subMax = (int) ($sub['qty_maximum'] ?? 0);

            if ($value < $subMin) {
                continue;
            }
            if ($subMax !== 0 && $value > $subMax) {
                continue;
            }

            $pricing = $this->normalizePricing($sub['pricing'] ?? []);
            $stepPrice = $this->resolvePricingAmount($pricing, $billingCycle);
            if ($stepPrice <= 0) {
                return [
                    'amount' => 0.0,
                    'selected_value' => $value,
                ];
            }

            $steps = $this->calculateRangeChargeSteps($value, $subMin, $rangeStep);

            return [
                'amount' => Money::multiply($stepPrice, $steps),
                'selected_value' => $value,
                'steps' => $steps,
            ];
        }

        // 超出所有阶梯且不存在"无上限"兜底段：拒绝按 0 元计费，防止超大配置值绕过定价。
        // 无可见阶梯的历史配置保持原行为（按 0 元），避免误伤既有产品。
        if ($visibleSubCount > 0) {
            $label = trim((string) ($item['name'] ?? $field));
            throw new BusinessException($label !== '' ? "配置项「{$label}」超出可选范围" : '配置值超出可选范围');
        }

        return [
            'amount' => 0.0,
            'selected_value' => $value,
        ];
    }

    private function findSelectedOptionPrice(array $item, string $selected, string $billingCycle): float
    {
        foreach ((array) ($item['sub'] ?? []) as $sub) {
            if ((int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }

            $subId = (string) ($sub['id'] ?? '');
            $subValue = (string) ($sub['option_name_first'] ?? $sub['option_name'] ?? $subId);

            if ($selected !== $subId && $selected !== $subValue) {
                continue;
            }

            $pricing = $this->normalizePricing($sub['pricing'] ?? []);
            $amount = $this->resolvePricingAmount($pricing, $billingCycle);

            if ($amount > 0) {
                return $amount;
            }

            return round((float) ($pricing[$billingCycle.'_fee'] ?? 0), 2);
        }

        return 0;
    }

    private function parseField(array $item): string
    {
        $field = trim((string) ($item['field'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $type = (int) ($item['option_type'] ?? -1);
        if (isset(self::TYPE_FIELD_MAP[$type])) {
            return self::TYPE_FIELD_MAP[$type];
        }

        $source = (string) ($item['option_name'] ?? $item['spec_key'] ?? '');
        $parts = explode('|', $source);

        return trim((string) ($parts[0] ?? ''));
    }

    /**
     * 提示型配置项（option_mode=text）：仅用于展示购买须知这类文字。
     *
     * 这类项常与真实规格项共用同一个 field（上游「明确禁止」的 field 就是 cpu），
     * 因此不能按 field 过滤，只能按配置项自身的呈现类型判断。
     *
     * @param  array<string, mixed>  $item
     */
    private function isTextOnlyConfigOption(array $item): bool
    {
        return trim((string) ($item['option_mode'] ?? '')) === 'text';
    }

    private function normalizeConfigValue(array $item, mixed $value): string|int|null
    {
        if (is_array($value)) {
            return null;
        }

        $type = (int) ($item['option_type'] ?? -1);
        $isRange = in_array($type, self::RANGE_TYPES, true)
            || trim((string) ($item['option_mode'] ?? '')) === 'range';

        if ($isRange) {
            $number = (int) $value;

            return $number > 0 ? $number : null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return Str::limit($text, 100, '');
    }

    private function normalizeHostname(string $hostname): string
    {
        $value = trim($hostname);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9-]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return Str::lower(Str::limit($value, 63, ''));
    }

    private function normalizePricing(mixed $pricing): array
    {
        if (! is_array($pricing)) {
            return [];
        }

        if (isset($pricing[0]) && is_array($pricing[0])) {
            return (array) $pricing[0];
        }

        return $pricing;
    }

    private function resolvePricingAmount(array $pricing, string $billingCycle): float
    {
        $amount = $pricing[$billingCycle] ?? null;
        if ($amount !== null && $amount !== '' && is_numeric($amount)) {
            return round((float) $amount, 2);
        }

        $monthly = $pricing['monthly'] ?? null;
        $months = self::BILLING_CYCLE_MONTHS[$billingCycle] ?? 0;

        if ($monthly === null || $monthly === '' || ! is_numeric($monthly) || $months <= 0) {
            return 0;
        }

        return round((float) $monthly * $months, 2);
    }

    private function calculateRangeChargeSteps(int $value, int $subMin, int $rangeStep): int
    {
        if ($value <= 0) {
            return 0;
        }

        if ($subMin <= 0) {
            return (int) ceil($value / $rangeStep);
        }

        return (int) floor(max($value - $subMin, 0) / $rangeStep) + 1;
    }

    private function resolveConfigLabel(array $item, string $field): string
    {
        $label = trim((string) ($item['name'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $source = trim((string) ($item['option_name'] ?? $item['spec_key'] ?? ''));
        if ($source !== '') {
            $parts = explode('|', $source, 2);
            $resolved = trim((string) ($parts[1] ?? $parts[0] ?? ''));
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $field;
    }

    private function formatAmount(float $amount): string
    {
        return Money::format($amount);
    }

    private function resolveConfigSnapshotValueLabel(array $item, string $field, string $selectedValue, bool $isRange): string
    {
        if ($selectedValue === '') {
            return '';
        }

        if (! $isRange) {
            $matched = $this->findSelectedOptionLabel($item, $selectedValue);
            if ($matched !== '') {
                return $matched;
            }
        }

        return $this->formatConfigSnapshotValue($field, $selectedValue, $item);
    }

    private function findSelectedOptionLabel(array $item, string $selectedValue): string
    {
        $normalized = Str::lower(trim($selectedValue));
        if ($normalized === '') {
            return '';
        }

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            if ((int) ($sub['hidden'] ?? 0) === 1) {
                continue;
            }

            $candidates = array_filter([
                Str::lower(trim((string) ($sub['id'] ?? ''))),
                Str::lower(trim((string) ($sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? ''))),
                Str::lower(trim((string) ($sub['option_name'] ?? $sub['version'] ?? $sub['label'] ?? ''))),
            ]);

            if (in_array($normalized, $candidates, true)) {
                return trim((string) ($sub['version'] ?? $sub['option_name'] ?? $sub['label'] ?? $sub['option_name_first'] ?? $sub['id'] ?? ''));
            }
        }

        foreach ($this->parseConfigParameterOptions((string) ($item['parameter'] ?? '')) as $option) {
            $candidates = array_filter([
                Str::lower(trim((string) ($option['id'] ?? ''))),
                Str::lower(trim((string) ($option['value'] ?? ''))),
                Str::lower(trim((string) ($option['label'] ?? ''))),
            ]);

            if (in_array($normalized, $candidates, true)) {
                return trim((string) ($option['label'] ?? $selectedValue));
            }
        }

        return '';
    }

    private function parseConfigParameterOptions(string $parameter): array
    {
        $text = trim(str_replace("\r", "\n", $parameter));
        if ($text === '') {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
        $segments = count($lines) > 1 ? $lines : $this->splitConfigParameterSegments($text);
        $options = [];

        foreach ($segments as $segment) {
            $pipePosition = strpos($segment, '|');
            if ($pipePosition === false) {
                $value = trim($segment);
                if ($value === '') {
                    continue;
                }

                $options[] = ['id' => $value, 'value' => $value, 'label' => $value];

                continue;
            }

            $value = trim(substr($segment, 0, $pipePosition));
            $label = trim(substr($segment, $pipePosition + 1));
            if ($value === '' && $label === '') {
                continue;
            }

            $options[] = [
                'id' => $value,
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return $options;
    }

    private function splitConfigParameterSegments(string $parameter): array
    {
        $segments = [];
        $buffer = '';

        foreach (array_filter(array_map('trim', explode(',', $parameter))) as $part) {
            $buffer = $buffer === '' ? $part : ($buffer.','.$part);

            if (str_contains($buffer, '|')) {
                $segments[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }

    private function formatConfigSnapshotValue(string $field, string $value, array $item = []): string
    {
        if ($value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return match ($field) {
                'cpu' => $this->normalizeNumericString($value).'核',
                'memory' => $this->formatMemorySnapshotValue((int) round((float) $value)),
                'bw', 'in_bw', 'out_bw' => $this->normalizeNumericString($value).'Mbps',
                'flow_limit' => $this->formatFlowSnapshotValue((float) $value),
                'ip_num', 'ipv6_num' => $this->normalizeNumericString($value).'个',
                'system_disk_size', 'data_disk_size' => $this->normalizeNumericString($value).'G',
                default => $this->appendConfigUnit($value, $item),
            };
        }

        if (in_array($field, ['system_disk_size', 'data_disk_size'], true)) {
            if (preg_match('/^lin:(\d+(?:\.\d+)?),win:(\d+(?:\.\d+)?)(?:,\d+)?$/i', $value, $matches) === 1) {
                return 'Linux '.$this->normalizeNumericString($matches[1]).'G / Windows '.$this->normalizeNumericString($matches[2]).'G';
            }

            $parts = array_map('trim', explode(',', $value));
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return $this->normalizeNumericString($parts[0]).'G';
            }
        }

        if ($field === 'flow_way') {
            return match (Str::lower($value)) {
                'in' => '入方向',
                'out' => '出方向',
                'all' => '进出汇总',
                default => $value,
            };
        }

        if ($field === 'network_type') {
            return match (Str::lower($value)) {
                'normal', 'classic' => '经典网络',
                'vpc' => 'VPC 网络',
                default => $value,
            };
        }

        return $value;
    }

    private function appendConfigUnit(string $value, array $item = []): string
    {
        $unit = trim((string) ($item['unit'] ?? ''));

        return $unit !== '' && is_numeric($value)
            ? $this->normalizeNumericString($value).$unit
            : $value;
    }

    private function formatMemorySnapshotValue(int $value): string
    {
        if ($value <= 0) {
            return '0';
        }

        if ($value < 1024) {
            return $value.'M';
        }

        if ($value % 1024 === 0) {
            return ((string) ($value / 1024)).'G';
        }

        return $value.'M';
    }

    private function formatFlowSnapshotValue(float $value): string
    {
        if ($value <= 0) {
            return '不限';
        }

        if ($value >= 1024 && fmod($value, 1024.0) === 0.0) {
            return $this->normalizeNumericString($value / 1024).'TB';
        }

        return $this->normalizeNumericString($value).'G';
    }

    private function normalizeNumericString(float|int|string $value): string
    {
        $number = (float) $value;

        if ((float) ((int) $number) === $number) {
            return (string) ((int) $number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
