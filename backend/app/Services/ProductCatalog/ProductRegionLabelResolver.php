<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

/**
 * 从上游绑定的配置结构里取「数据中心 / 区域 / 线路」标签。
 *
 * 为什么需要它：同一个三级分组里的商品并不总是同一地区——「美国/香港/日本 轻量」
 * 一个分组里就有三个区域同规格商品，「16核16G 99元」活动分组里更是十几个不同
 * 机房。只写规格（2核2G）用户根本分不清买的是哪台机器，所以商品名在「同组存在
 * 多个区域」时必须带上区域。
 *
 * 数据来源是 product_upstream_bindings.option_schema_json 里的第一个区域类选项
 * （field 为 area/region/location/line，或名称是 数据中心/区域/地区/机房/节点/线路），
 * 取它的第一个子项标签（如「美国轻量区」「香港轻量区」）。
 */
class ProductRegionLabelResolver
{
    /** 上游把区域选项叫这些名字 */
    private const AREA_OPTION_NAMES = ['数据中心', '区域', '地区', '机房', '节点', '位置', '线路'];

    /** 上游把区域选项放在这些 field 上 */
    private const AREA_OPTION_FIELDS = ['area', 'region', 'location', 'zone', 'line', 'network_line'];

    /**
     * @param  string|null  $optionSchemaJson  product_upstream_bindings.option_schema_json
     */
    public function fromOptionSchema(?string $optionSchemaJson): string
    {
        $schema = json_decode((string) $optionSchemaJson, true);
        if (! is_array($schema)) {
            return '';
        }

        foreach ($schema as $option) {
            if (! is_array($option)) {
                continue;
            }

            if (! $this->isAreaOption($option)) {
                continue;
            }

            foreach ((array) ($option['sub'] ?? []) as $sub) {
                if (! is_array($sub)) {
                    continue;
                }

                $label = trim((string) ($sub['option_name'] ?? ($sub['version'] ?? '')));
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function isAreaOption(array $option): bool
    {
        $name = trim((string) ($option['name'] ?? ''));
        if ($name !== '' && in_array($name, self::AREA_OPTION_NAMES, true)) {
            return true;
        }

        $field = strtolower(trim((string) ($option['field'] ?? '')));

        return $field !== '' && in_array($field, self::AREA_OPTION_FIELDS, true);
    }
}
