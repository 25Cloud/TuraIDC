<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKeyUsageLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpenApiPruneUsageLogsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deletes_expired_logs_and_keeps_recent_ones(): void
    {
        $expired = ApiKeyUsageLog::query()->create([
            'api_key_id' => 0,
            'user_id' => 0,
            'method' => 'GET',
            'path' => '/api/v2/open/prune-expired',
            'status_code' => 401,
            'ip' => '203.0.113.9',
            'duration_ms' => 1,
            'created_at' => now()->subDays(120),
        ]);

        $recent = ApiKeyUsageLog::query()->create([
            'api_key_id' => 0,
            'user_id' => 0,
            'method' => 'GET',
            'path' => '/api/v2/open/prune-recent',
            'status_code' => 200,
            'ip' => '203.0.113.9',
            'duration_ms' => 2,
            'created_at' => now()->subDays(2),
        ]);

        Setting::setValues('open_api', ['usage_log_retention_days' => '90']);

        $this->artisan('open-api:prune-usage-logs')->expectsOutputToContain('删除')->assertSuccessful();

        $this->assertNull(ApiKeyUsageLog::query()->find($expired->id));
        $this->assertNotNull(ApiKeyUsageLog::query()->find($recent->id));

        // 显式覆盖保留天数
        $this->artisan('open-api:prune-usage-logs', ['--days' => '1'])->assertSuccessful();
        $this->assertNull(ApiKeyUsageLog::query()->find($recent->id));
    }
}
