<?php

declare(strict_types=1);

namespace App\Services\OpenApi;

use App\Models\Setting;

class OpenApiConfig
{
    public const GROUP = 'open_api';

    public function enabled(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'enabled', 0) === 1;
    }

    public function requirePhone(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'require_phone', 0) === 1;
    }

    public function requireVerified(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'require_verified', 0) === 1;
    }

    public function maxKeysPerUser(): int
    {
        return max((int) Setting::getValue(self::GROUP, 'max_keys_per_user', 10), 1);
    }

    public function rateLimitPerMinute(): int
    {
        return max((int) Setting::getValue(self::GROUP, 'rate_limit', 60), 1);
    }

    /**
     * 写操作（下单/余额支付/电源/续费/重装）的独立限流阈值。
     *
     * 设计文档承诺「关键写接口单独收紧」：写接口涉及真实扣费与不可逆操作，
     * 默认阈值低于全局读阈值，管理员可通过 open_api.write_rate_limit 调整。
     */
    public function writeRateLimitPerMinute(): int
    {
        return max((int) Setting::getValue(self::GROUP, 'write_rate_limit', 30), 1);
    }

    /** 使用日志保留天数（超出部分由 open-api:prune-usage-logs 定期删除） */
    public function usageLogRetentionDays(): int
    {
        return max((int) Setting::getValue(self::GROUP, 'usage_log_retention_days', 90), 1);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled() ? 1 : 0,
            'require_phone' => $this->requirePhone() ? 1 : 0,
            'require_verified' => $this->requireVerified() ? 1 : 0,
            'max_keys_per_user' => $this->maxKeysPerUser(),
            'rate_limit' => $this->rateLimitPerMinute(),
            'write_rate_limit' => $this->writeRateLimitPerMinute(),
            'usage_log_retention_days' => $this->usageLogRetentionDays(),
        ];
    }
}
