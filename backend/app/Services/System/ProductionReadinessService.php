<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\ScheduleTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProductionReadinessService
{
    /**
     * @return array{ready: bool, checks: array{database: bool, cache: bool, storage: bool, scheduler: bool}}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->databaseIsReady(),
            'cache' => $this->cacheIsReady(),
            'storage' => $this->storageIsReady(),
            'scheduler' => $this->schedulerIsReady(),
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
        ];
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::connection()->selectOne('SELECT 1 AS ready');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsReady(): bool
    {
        $key = 'health:readiness:'.Str::uuid()->toString();

        try {
            Cache::put($key, 'ready', 10);

            return Cache::get($key) === 'ready';
        } catch (Throwable) {
            return false;
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // 读取或写入已经失败时，清理失败不改变就绪结论。
            }
        }
    }

    private function storageIsReady(): bool
    {
        foreach ([storage_path(), base_path('bootstrap/cache')] as $directory) {
            if (! is_dir($directory) || ! is_writable($directory)) {
                return false;
            }
        }

        return true;
    }

    private function schedulerIsReady(): bool
    {
        try {
            $latestHeartbeat = ScheduleTick::query()->max('triggered_at');
            if (! is_string($latestHeartbeat) || trim($latestHeartbeat) === '') {
                return false;
            }

            $maximumAge = max(60, (int) config('health.scheduler_max_age_seconds', 180));

            return CarbonImmutable::parse($latestHeartbeat)
                ->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($maximumAge));
        } catch (Throwable) {
            return false;
        }
    }
}
