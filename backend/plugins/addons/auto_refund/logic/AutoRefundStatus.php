<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Logic;

use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundService;
use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundSettings;

/**
 * 插件级自检：给插件管理页的「试运行」与运维排查用。
 *
 * 严格只读、无副作用：不写配置、不写表、**不发上游请求**。
 * 「上游通不通」属于管理端 `/upstream/test` 的动作，不能放在 execute() 里——
 * 平台会在插件扫描/启用等时机调用 execute()，那里一次网络请求就会拖慢整个后台。
 */
final class AutoRefundStatus
{
    public function __construct(
        private readonly AutoRefundSettings $settings,
        private readonly AutoRefundService $service,
    ) {}

    /**
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    public function handle(string $action): array
    {
        return match ($action) {
            'auto_refund.status', 'status' => $this->status(),
            'auto_refund.policy', 'policy' => $this->policy(),
            default => [
                'success' => false,
                'action' => $action,
                'message' => "未知的插件动作：{$action}",
                'data' => [],
            ],
        };
    }

    /**
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    private function status(): array
    {
        $snapshot = $this->settings->snapshot();
        $tableReady = $this->service->tableReady();
        $ready = (bool) $snapshot['configured'];

        return [
            'success' => true,
            'action' => 'auto_refund.status',
            'message' => $ready
                ? '自助退款上游通道已配置'
                : '尚无可用出站通道（上游 api.php 或上游工单接口未配置），控制台退款中心显示未开放',
            'data' => [
                'enabled' => (bool) $snapshot['plugin_enabled'],
                'config_enabled' => (bool) $snapshot['enabled'],
                'configured' => $ready,
                'upstream_configured' => (bool) $snapshot['upstream_configured'],
                'has_api_key' => (bool) $snapshot['has_api_key'],
                'bridge_ready' => (bool) $snapshot['bridge_ready'],
                'ticket_ready' => (bool) $snapshot['ticket_ready'],
                'ticket' => $snapshot['ticket'],
                'upstream_host' => (string) $snapshot['upstream_host'],
                'request_timeout' => (int) $snapshot['request_timeout'],
                'table_ready' => $tableReady,
                'rule_table_ready' => $this->service->productRuleTableReady(),
                'open' => $this->service->isOpen(),
                'policy' => $snapshot['policy'],
                'table' => 'auto_refund_applications',
                'endpoints' => [
                    'client' => '/api/v2/client/auto-refund',
                    'admin' => '/api/v2/admin/auto-refund',
                ],
            ],
        ];
    }

    /**
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    private function policy(): array
    {
        $snapshot = $this->settings->snapshot();
        $policy = (array) $snapshot['policy'];

        return [
            'success' => true,
            'action' => 'auto_refund.policy',
            'message' => '退款策略按商品配置：在管理端「自助退款 → 商品策略」逐商品设置类型/规则/要求',
            'data' => [
                'policy' => $policy,
                'ticket' => $snapshot['ticket'],
                'refund_type_options' => $policy['refund_types'] ?? [],
                'channel_options' => AutoRefundService::channelOptions(),
                'blocked_reason_labels' => self::blockedReasonLabels(),
            ],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function blockedReasonLabels(): array
    {
        return AutoRefundService::blockedReasonLabels();
    }
}
