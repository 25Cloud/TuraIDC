<?php

declare(strict_types=1);

namespace App\Services\OpenApi;

use App\Models\ApiKeyUsageLog;

/**
 * 两条对客 API 链路的共用调用审计。
 *
 * 开放接口（/api/v2/open）与魔方财务对接（/api/v2/zjmf）协议不同，但「谁在什么时间
 * 从哪个 IP 调了哪个路径、结果如何」是同一种事实，因此共用一张表，用 channel 区分归属。
 */
class ApiKeyUsageLogService
{
    public function record(
        int $apiKeyId,
        int $userId,
        string $method,
        string $path,
        int $statusCode,
        string $ip,
        int $durationMs,
        string $channel = ApiKeyUsageLog::CHANNEL_OPEN_API,
    ): void {
        ApiKeyUsageLog::query()->create([
            'api_key_id' => $apiKeyId,
            'user_id' => $userId,
            'channel' => $channel,
            'method' => $method,
            'path' => mb_substr($path, 0, 250),
            'status_code' => $statusCode,
            'ip' => $ip,
            'duration_ms' => max($durationMs, 0),
            'created_at' => now(),
        ]);
    }
}