<?php

declare(strict_types=1);

namespace App\Services\Order\Concerns;

use App\Constants\BillingCycle;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Str;

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

        foreach ((array) ($product->config_options ?? []) as $item) {
            if ((int) ($item['hidden'] ?? 0) === 1) {
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
