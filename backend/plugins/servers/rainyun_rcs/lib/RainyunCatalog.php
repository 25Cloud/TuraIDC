<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

final class RainyunCatalog
{
    public function __construct(private readonly RainyunClient $client) {}

    public function plans(Supplier $supplier): array
    {
        $plans = $this->client->request($supplier, 'GET', '/product/rcs/plans');
        throw_if(! is_array($plans), new BusinessException('雨云套餐列表格式不正确', 42200));

        return array_values(array_filter($plans, fn ($p) => is_array($p) && (int) ($p['id'] ?? 0) > 0));
    }

    public function plan(Supplier $supplier, int $id): array
    {
        foreach ($this->plans($supplier) as $plan) {
            if ((int) $plan['id'] === $id) {
                return $plan;
            }
        }
        throw new BusinessException('雨云套餐不存在或已不可用', 42200);
    }

    public function images(Supplier $supplier, array $plan, int $timeout = 30): array
    {
        $images = $this->client->request($supplier, 'GET', '/product/rcs/os-templates', timeout: $timeout);
        throw_if(! is_array($images), new BusinessException('雨云系统镜像列表格式不正确', 42200));

        return array_values(array_filter($images, static fn ($os) => is_array($os)
            && ($os['is_available'] ?? false)
            && ($os['region'] ?? '') === ($plan['region'] ?? '')
            && ($os['subtype'] ?? '') === ($plan['subtype'] ?? '')
            && (empty($os['machine']) || $os['machine'] === ($plan['machine'] ?? ''))));
    }

    public function catalog(Supplier $supplier): array
    {
        $products = array_map($this->product(...), array_filter($this->plans($supplier), fn ($p) => $p['is_selling'] ?? false));
        $groups = [];
        foreach ($products as $product) {
            $key = $product['group_name'];
            $groups[$key] ??= ['key' => $key, 'label' => $key, 'items' => []];
            $groups[$key]['items'][] = $product;
        }

        return ['products' => array_values($products), 'groups' => array_values($groups)];
    }

    public function product(array $plan): array
    {
        $price = RainyunValues::money(RainyunValues::cents($plan['price'] ?? '0', '雨云套餐价格'));

        return [
            'id' => (int) $plan['id'], 'name' => (string) ($plan['chinese'] ?? $plan['plan_name']),
            'type' => 'cloud_server', 'type_label' => '云服务器',
            'description' => ($plan['region'] ?? '').' / '.($plan['line'] ?? '').' / '.($plan['machine'] ?? ''),
            'billingcycle' => 'monthly', 'product_price' => $price, 'monthly_price' => $price,
            'setup_fee' => '0.00', 'allow_qty' => 1, 'stock_control' => 1,
            'stock' => (int) ($plan['available_stock'] ?? 0), 'qty' => (int) ($plan['available_stock'] ?? 0),
            'first_group_name' => '雨云', 'second_group_name' => (string) ($plan['region'] ?? ''),
            'group_name' => ($plan['region'] ?? '').' / '.($plan['line'] ?? ''),
        ];
    }

    public function template(Supplier $supplier, int $id): array
    {
        $plan = $this->plan($supplier, $id);
        $images = $this->images($supplier, $plan);
        $diskTypes = ($plan['disk_selling'] ?? null) ?: array_keys($plan['disk_size'] ?? []);
        $ipFlags = ($plan['ip_selling'] ?? null) ?: array_keys($plan['ip_prices'] ?? ['' => 0]);
        $options = [
            $this->option(1, 'plan_id', '雨云套餐', [(string) $id => (string) ($plan['chinese'] ?? $id)], true),
            $this->option(2, 'os_id', '操作系统', array_column($images, 'chinese_name', 'id')),
            $this->option(3, 'disk_type', '系统盘类型', array_combine($diskTypes, $diskTypes)),
            $this->option(4, 'with_eip_num', '独立 IP 数量', ['0' => '不附加']),
            $this->option(5, 'with_eip_flags', '独立 IP 线路', array_combine($ipFlags, array_map(fn ($v) => $v === '' ? '默认线路' : $v, $ipFlags))),
            $this->option(6, 'add_disk_size', '系统盘附加容量（GB）', ['0' => '不附加']),
            $this->option(7, 'try', '试用', ['false' => '关闭'], true),
            $this->option(8, 'with_coupon_id', '雨云优惠券 ID', ['0' => '不使用'], true),
        ];

        return ['product_id' => $id, 'product' => $this->product($plan), 'config_options' => array_values(array_filter($options, fn ($o) => $o['sub'] !== [])), 'auto_filled_fields' => ['plan_id']];
    }

    private function option(int $id, string $field, string $name, array $values, bool $hidden = false): array
    {
        $sub = [];
        foreach ($values as $value => $label) {
            $sub[] = ['id' => (string) $value, 'value' => (string) $value, 'name' => (string) $label,
                'label' => (string) $label, 'option_name' => (string) $label, 'option_name_first' => (string) $value,
                'monthly' => '0.00', 'pricing' => ['monthly' => '0.00'], 'is_default' => $sub === [] ? 1 : 0];
        }

        return ['id' => $id, 'uid' => 'rainyun-'.$field, 'source' => 'rainyun_rcs', 'field' => $field,
            'name' => $name, 'option_name' => $name, 'type' => 'select', 'option_type' => 'select',
            'option_mode' => 'select', 'hidden' => $hidden, 'required' => true, 'sort_order' => $id,
            'sub' => $sub, 'sub_items' => $sub, 'range_pricing' => []];
    }
}
