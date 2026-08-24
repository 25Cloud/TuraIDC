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
        ];
    }
}
