<?php

declare(strict_types=1);

namespace App\Services\OpenApi;

use App\Models\ApiKeyUsageLog;

class ApiKeyUsageLogService
{
    public function record(int $apiKeyId, int $userId, string $method, string $path, int $statusCode, string $ip, int $durationMs): void
    {
        ApiKeyUsageLog::query()->create([
            'api_key_id' => $apiKeyId,
            'user_id' => $userId,
            'method' => $method,
            'path' => mb_substr($path, 0, 250),
            'status_code' => $statusCode,
            'ip' => $ip,
            'duration_ms' => max($durationMs, 0),
            'created_at' => now(),
        ]);
    }
}
