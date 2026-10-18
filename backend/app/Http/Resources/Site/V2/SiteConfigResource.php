<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteConfigResource extends JsonResource
{
    private const FIELDS = [
        'site_name',
        'browser_title',
        'site_logo',
        'site_favicon',
        'client_console_icon',
        'service_qq_group',
        'service_phone',
        'service_email',
        'service_hours',
        'support_group_title',
        'support_group_text',
        'support_group_qr',
        'support_group_link',
        'terms_url',
        'privacy_url',
        'icp_record',
        'value_added_license',
        // 品牌主色与圆角：前端据此覆盖 TDesign / Element Plus 的 CSS 变量，
        // 值已在 SiteConfigPayload 做过严格格式校验，这里只做透传。
        'brand_primary_color',
        'brand_radius',
        // 全站商品展示方式（list / card）：选购页据此决定渲列表还是卡片。
        // 它只在 SiteConfigPayload 里算出来、这里漏登记过一次，导致后台配了也不生效，
        // 新增站点级字段时务必两处同时改（回归用例见 V2SiteHomeApiTest）。
        'product_display_mode',
        // 选购页展示层开关（后台「商品展示」独立页面维护）：
        // 卡片是否展示库存 / 独立配置页是否展示机型对比 / 版心居中或铺满 / 版心最大宽度。
        'product_card_show_stock',
        'product_detail_spec_compare',
        'product_page_layout',
        'product_page_max_width',
    ];

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return collect(self::FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => (string) ($payload[$field] ?? '')])
            ->all();
    }
}
