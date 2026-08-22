<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

/**
 * 工单详情 upstream_delivery 嵌套字段白名单。
 *
 * 管理端工单详情与上游投递状态接口共享同一投影，白名单必须保持一致；
 * 抽成 trait 后两处测试断言共用一份定义，避免单独维护时漂移。
 */
trait UpstreamDeliveryWhitelist
{
    /**
     * @return list<string>
     */
    private function upstreamDeliveryWhitelist(): array
    {
        return [
            'configured',
            'status',
            'status_label',
            'provider_key',
            'supplier_id',
            'upstream_department_id',
            'upstream_service_id',
            'upstream_ticket_id',
            'attempts',
            'last_attempt_at',
            'delivered_at',
            'last_error',
            'last_event',
        ];
    }
}
