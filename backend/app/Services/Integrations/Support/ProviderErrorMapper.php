<?php

declare(strict_types=1);

namespace App\Services\Integrations\Support;

final class ProviderErrorMapper
{
    public function toUserMessage(string $provider, string $action, mixed $rawMessage = null): string
    {
        $actionLabel = $this->actionLabel($action);

        return match (trim($provider)) {
            'alipay', 'alipay_f2f', 'yipay' => $actionLabel.'失败，请稍后重试或联系管理员处理',
            'stay33' => $actionLabel.'失败，请稍后重新发起实名认证',
            'hosting_panel_api' => $actionLabel.'失败，主机面板接口暂时不可用',
            default => $actionLabel.'失败，请稍后重试',
        };
    }

    private function actionLabel(string $action): string
    {
        return match (trim($action)) {
            'precreate', 'pay', 'payment' => '支付请求',
            'refund' => '退款请求',
            'callback' => '回调处理',
            'verification', 'identity' => '实名认证',
            'provision' => '服务开通',
            'renew' => '服务续费',
            'sync_status' => '状态同步',
            default => trim($action) !== '' ? trim($action) : '第三方请求',
        };
    }
}
