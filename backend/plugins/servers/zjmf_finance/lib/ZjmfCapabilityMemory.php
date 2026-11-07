<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use Illuminate\Support\Facades\Cache;

/**
 * 记住「这个供应商实测走通了哪个接口」。
 *
 * 魔方财务各版本的路由差异没有可靠的版本探测接口，只能靠打一次看结果。
 * 探测结果按供应商落缓存，后续请求直接走通的那条，避免每次都先吃一个 404
 * （404 会进上游的访问日志，也会把开通链路拖慢一秒多）。
 */
final class ZjmfCapabilityMemory
{
    private const TTL_SECONDS = 21600;

    public function __construct(private readonly string $prefix = 'zjmf:cap') {}

    public function remember(int $supplierId, string $capability, string $value): void
    {
        if ($supplierId <= 0 || $value === '') {
            return;
        }

        try {
            Cache::put($this->key($supplierId, $capability), $value, now()->addSeconds(self::TTL_SECONDS));
        } catch (\Throwable) {
            // 缓存不可用不影响主流程，最多退化成每次探测
        }
    }

    public function recall(int $supplierId, string $capability): ?string
    {
        if ($supplierId <= 0) {
            return null;
        }

        try {
            $value = Cache::get($this->key($supplierId, $capability));
        } catch (\Throwable) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    public function forget(int $supplierId, string $capability): void
    {
        if ($supplierId <= 0) {
            return;
        }

        try {
            Cache::forget($this->key($supplierId, $capability));
        } catch (\Throwable) {
            // 同上
        }
    }

    private function key(int $supplierId, string $capability): string
    {
        return sprintf('%s:%d:%s', $this->prefix, $supplierId, $capability);
    }
}
