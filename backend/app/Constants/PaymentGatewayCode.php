<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * 支付网关编码集中定义，区分业务入账值和第三方插件 key。
 */
final class PaymentGatewayCode
{
    public const ALIPAY = 'alipay';

    public const EPAY = 'epay';

    public const BALANCE = 'balance';

    public const WECHAT = 'wechat';

    public const STRIPE = 'stripe';

    public const MANUAL = 'manual';

    public const FREE = 'free';

    public const ALIPAY_F2F_PLUGIN = 'alipay_f2f';

    public const THIRD_PARTY_GATEWAYS = [
        self::ALIPAY,
        self::EPAY,
        self::WECHAT,
        self::STRIPE,
    ];

    public const LABELS = [
        self::ALIPAY => '支付宝支付',
        self::EPAY => '易支付',
        self::BALANCE => '余额支付',
        self::WECHAT => '微信支付',
        self::STRIPE => 'Stripe 支付',
        self::MANUAL => '管理员手动',
        self::FREE => '免费开通',
    ];

    public static function label(string $gateway): string
    {
        return self::LABELS[$gateway] ?? $gateway;
    }

    public static function normalize(string $gateway): string
    {
        $gateway = trim($gateway);

        return match ($gateway) {
            self::ALIPAY_F2F_PLUGIN, 'ali_pay' => self::ALIPAY,
            // 存量数据的网关编码（历史 payments.gateway_key / 回调 URL / 客户端筛选值），
            // 归一到 epay 后与历史行匹配，避免改码后查不到历史订单。
            'yipay', 'yi_pay' => self::EPAY,
            default => $gateway,
        };
    }

    public static function thirdPartyGateways(): array
    {
        return self::THIRD_PARTY_GATEWAYS;
    }

    public static function isThirdParty(string $gateway): bool
    {
        return in_array($gateway, self::THIRD_PARTY_GATEWAYS, true);
    }
}
