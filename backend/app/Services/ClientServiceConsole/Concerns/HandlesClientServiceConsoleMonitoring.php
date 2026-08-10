<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole\Concerns;

use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Support\SensitiveDataSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait HandlesClientServiceConsoleMonitoring
{
    private const MONITOR_UPSTREAM_CONNECT_TIMEOUT_SECONDS = 5;

    private const MONITOR_UPSTREAM_TIMEOUT_SECONDS = 8;

    public function getMonitorForUser(User $user, int $serviceId, array $filters = []): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $startedAt = microtime(true);
        $range = $this->resolveMonitorRange($filters);
        $selectedType = trim((string) ($filters['type'] ?? ''));
        $fresh = (bool) ($filters['fresh'] ?? false);

        if (! $this->canManageService($service)) {
            return $this->buildEmptyMonitorResponse($range, '当前服务暂不支持监控图表');
        }

        $responseCacheKey = $this->buildMonitorResponseCacheKey($service, [
            'type' => $selectedType,
            'range' => $range,
        ]);
        if (! $fresh) {
            $cachedResponse = Cache::get($responseCacheKey);
            if (is_array($cachedResponse) && $cachedResponse !== []) {
                return $cachedResponse;
            }
        }

        if (! $fresh && $this->canManageService($service)) {
            [$cachedSupplier, $cachedHostId] = $this->resolveManagedSupplierAndHost($service);
            $cachedChartOptions = $selectedType !== ''
                ? $this->getCachedMonitorChartOptions($cachedSupplier, $cachedHostId)
                : [];
            $cachedSelectedType = $selectedType;

            if ($cachedSelectedType === '' && $cachedChartOptions !== []) {
                $cachedSelectedType = (string) ($cachedChartOptions[0]['value'] ?? '');
            }

            if ($cachedSelectedType !== '') {
                $cachedChart = $this->getCachedMonitorChart($cachedSupplier, $cachedHostId, $cachedSelectedType, $range['start'], $range['end']);
                if (is_array($cachedChart)) {
                    $chartData = is_array($cachedChart['chart'] ?? null) ? $cachedChart['chart'] : null;
                    $summary = is_array($cachedChart['summary'] ?? null) ? $cachedChart['summary'] : null;

                    $response = [
                        'supported' => true,
                        'message' => is_array($chartData) && ($chartData['list'] ?? []) !== [] ? '' : '当前时间范围内暂无监控数据',
                        'error' => '',
                        'options' => $cachedChartOptions,
                        'selected_type' => $cachedSelectedType,
                        'selected_label' => $this->resolveChartOptionLabel($cachedSelectedType, $cachedChartOptions),
                        'range' => $range,
                        'chart' => $chartData,
                        'summary' => $summary,
                    ];

                    if (! $fresh) {
                        Cache::put($responseCacheKey, $response, now()->addSeconds(self::MONITOR_RESPONSE_CACHE_TTL_SECONDS));
                    }

                    return $response;
                }
            }
        }

        try {
            $contextStartedAt = microtime(true);
            [, $supplier, $hostId, $jwt] = $this->resolveUpstreamContext($service);
            $contextDurationMs = $this->elapsedMilliseconds($contextStartedAt);
            $chartOptions = $selectedType !== ''
                ? $this->getCachedMonitorChartOptions($supplier, $hostId)
                : [];
            $moduleDurationMs = 0;

            if ($selectedType === '') {
                $moduleStartedAt = microtime(true);
                $modules = $this->fetchSupportedModules($supplier, $hostId, $jwt);
                $moduleDurationMs = $this->elapsedMilliseconds($moduleStartedAt);
                $chartOptions = $this->extractChartOptions($modules);
            }

            if ($selectedType === '' && $chartOptions !== []) {
                $selectedType = (string) ($chartOptions[0]['value'] ?? '');
            }

            if ($selectedType === '') {
                return $this->buildEmptyMonitorResponse($range, '上游未返回可用的监控指标', $chartOptions);
            }

            $chartStartedAt = microtime(true);
            $chart = $this->fetchMonitorChart($supplier, $hostId, $jwt, $selectedType, $range['start'], $range['end'], $fresh);
            $chartDurationMs = $this->elapsedMilliseconds($chartStartedAt);
            $chartData = is_array($chart['chart'] ?? null) ? $chart['chart'] : null;
            $summary = is_array($chart['summary'] ?? null) ? $chart['summary'] : null;

            Log::info('[客户端监控] 单图监控耗时', [
                'service_id' => $service->id,
                'host_id' => $hostId,
                'type' => $selectedType,
                'fresh' => $fresh,
                'context_ms' => $contextDurationMs,
                'module_ms' => $moduleDurationMs,
                'chart_ms' => $chartDurationMs,
                'total_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            $response = [
                'supported' => true,
                'message' => is_array($chartData) && ($chartData['list'] ?? []) !== [] ? '' : '当前时间范围内暂无监控数据',
                'error' => '',
                'options' => $chartOptions,
                'selected_type' => $selectedType,
                'selected_label' => $this->resolveChartOptionLabel($selectedType, $chartOptions),
                'range' => $range,
                'chart' => $chartData,
                'summary' => $summary,
            ];

            if (! $fresh) {
                Cache::put($responseCacheKey, $response, now()->addSeconds(self::MONITOR_RESPONSE_CACHE_TTL_SECONDS));
            }

            return $response;
        } catch (\Throwable $exception) {
            Log::warning('[客户端监控] 单图监控失败', [
                'service_id' => $service->id,
                'requested_type' => $selectedType,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return [
                'supported' => true,
                'message' => '',
                'error' => '获取监控数据失败，请稍后重试',
                'options' => [],
                'selected_type' => $selectedType,
                'selected_label' => '',
                'range' => $range,
                'chart' => null,
                'summary' => null,
            ];
        } finally {
            Log::info('[客户端监控] 单图监控结束', [
                'service_id' => $service->id,
                'requested_type' => $selectedType,
                'total_ms' => $this->elapsedMilliseconds($startedAt),
            ]);
        }
    }

    public function getMonitorBatchForUser(User $user, int $serviceId, array $filters = []): array
    {
        $service = $this->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $startedAt = microtime(true);
        $range = $this->resolveMonitorRange($filters);
        $fresh = (bool) ($filters['fresh'] ?? false);
        $requestedTypes = collect($filters['types'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $item) => $item !== '')
            ->unique()
            ->values()
            ->all();
        $requestedLimit = max((int) ($filters['limit'] ?? 0), 0);

        if (! $this->canManageService($service)) {
            return [
                'supported' => false,
                'message' => '当前服务暂不支持监控图表',
                'error' => '',
                'options' => [],
                'range' => $range,
                'charts' => [],
            ];
        }

        $responseCacheKey = $this->buildMonitorBatchResponseCacheKey($service, [
            'types' => $requestedTypes,
            'limit' => $requestedLimit,
            'range' => $range,
        ]);
        if (! $fresh) {
            $cachedResponse = Cache::get($responseCacheKey);
            if (is_array($cachedResponse) && $cachedResponse !== []) {
                return $cachedResponse;
            }
        }

        if (! $fresh && $this->canManageService($service)) {
            [$cachedSupplier, $cachedHostId] = $this->resolveManagedSupplierAndHost($service);
            $cachedChartOptions = $this->getCachedMonitorChartOptions($cachedSupplier, $cachedHostId);
            $cachedAvailableTypes = $requestedTypes === []
                ? collect($cachedChartOptions)
                    ->map(fn (array $item) => trim((string) ($item['value'] ?? '')))
                    ->filter(fn (string $item) => $item !== '')
                    ->values()
                    ->all()
                : ($cachedChartOptions !== []
                    ? collect($cachedChartOptions)
                        ->map(fn (array $item) => trim((string) ($item['value'] ?? '')))
                        ->filter(fn (string $item) => $item !== '')
                        ->values()
                        ->all()
                    : $requestedTypes);

            $cachedTypes = $requestedTypes !== []
                ? array_values(array_intersect($requestedTypes, $cachedAvailableTypes))
                : ($requestedLimit > 0 ? array_slice($cachedAvailableTypes, 0, $requestedLimit) : $cachedAvailableTypes);

            if ($cachedTypes !== []) {
                $cachedCharts = $this->getCachedMonitorChartsMap($cachedSupplier, $cachedHostId, $cachedTypes, $range['start'], $range['end']);
                $allCached = count($cachedCharts) === count($cachedTypes);

                if ($allCached) {
                    $response = [
                        'supported' => true,
                        'message' => '',
                        'error' => '',
                        'options' => $cachedChartOptions,
                        'range' => $range,
                        'charts' => collect($cachedTypes)->map(function (string $type) use ($cachedCharts, $cachedChartOptions) {
                            $chart = $cachedCharts[$type] ?? null;
                            $chartData = is_array($chart['chart'] ?? null) ? $chart['chart'] : null;
                            $summary = is_array($chart['summary'] ?? null) ? $chart['summary'] : null;
                            $label = $this->resolveChartOptionLabel($type, $cachedChartOptions, $type);

                            return [
                                'type' => $type,
                                'label' => $this->resolveChartOptionLabel($type, $cachedChartOptions, $label),
                                'message' => is_array($chartData) && ($chartData['list'] ?? []) !== [] ? '' : '当前时间范围内暂无监控数据',
                                'error' => '',
                                'chart' => $chartData,
                                'summary' => $summary,
                            ];
                        })->values()->all(),
                    ];

                    if (! $fresh) {
                        Cache::put($responseCacheKey, $response, now()->addSeconds(self::MONITOR_RESPONSE_CACHE_TTL_SECONDS));
                    }

                    return $response;
                }
            }
        }

        try {
            $contextStartedAt = microtime(true);
            [, $supplier, $hostId, $jwt] = $this->resolveUpstreamContext($service);
            $contextDurationMs = $this->elapsedMilliseconds($contextStartedAt);
            $moduleDurationMs = 0;
            $chartOptions = [];
            $availableTypes = [];

            if ($requestedTypes === []) {
                $moduleStartedAt = microtime(true);
                $modules = $this->fetchSupportedModules($supplier, $hostId, $jwt);
                $moduleDurationMs = $this->elapsedMilliseconds($moduleStartedAt);
                $chartOptions = $this->extractChartOptions($modules);
                $availableTypes = collect($chartOptions)
                    ->map(fn (array $item) => trim((string) ($item['value'] ?? '')))
                    ->filter(fn (string $item) => $item !== '')
                    ->values()
                    ->all();
            } else {
                $chartOptions = $this->getCachedMonitorChartOptions($supplier, $hostId);
                $availableTypes = $chartOptions !== []
                    ? collect($chartOptions)
                        ->map(fn (array $item) => trim((string) ($item['value'] ?? '')))
                        ->filter(fn (string $item) => $item !== '')
                        ->values()
                        ->all()
                    : $requestedTypes;
            }

            $types = $requestedTypes !== []
                ? array_values(array_intersect($requestedTypes, $availableTypes))
                : ($requestedLimit > 0 ? array_slice($availableTypes, 0, $requestedLimit) : $availableTypes);

            if ($types === []) {
                return [
                    'supported' => false,
                    'message' => '上游未返回可用的监控指标',
                    'error' => '',
                    'options' => $chartOptions,
                    'range' => $range,
                    'charts' => [],
                ];
            }

            $chartStartedAt = microtime(true);
            $charts = $this->fetchMonitorChartsBatch($supplier, $hostId, $jwt, $types, $range, $chartOptions, $fresh);
            $chartDurationMs = $this->elapsedMilliseconds($chartStartedAt);

            Log::info('[客户端监控] 批量监控耗时', [
                'service_id' => $service->id,
                'host_id' => $hostId,
                'requested_types' => $requestedTypes,
                'resolved_types' => $types,
                'fresh' => $fresh,
                'context_ms' => $contextDurationMs,
                'module_ms' => $moduleDurationMs,
                'chart_ms' => $chartDurationMs,
                'total_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            $response = [
                'supported' => true,
                'message' => '',
                'error' => '',
                'options' => $chartOptions,
                'range' => $range,
                'charts' => $charts,
            ];

            if (! $fresh) {
                Cache::put($responseCacheKey, $response, now()->addSeconds(self::MONITOR_RESPONSE_CACHE_TTL_SECONDS));
            }

            return $response;
        } catch (\Throwable $exception) {
            Log::warning('[客户端监控] 批量监控失败', [
                'service_id' => $service->id,
                'requested_types' => $requestedTypes,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return [
                'supported' => true,
                'message' => '',
                'error' => '获取监控数据失败，请稍后重试',
                'options' => [],
                'range' => $range,
                'charts' => [],
            ];
        } finally {
            Log::info('[客户端监控] 批量监控结束', [
                'service_id' => $service->id,
                'requested_types' => $requestedTypes,
                'total_ms' => $this->elapsedMilliseconds($startedAt),
            ]);
        }
    }

    private function getCachedMonitorChartOptions(Supplier $supplier, int $hostId): array
    {
        $cachedModules = Cache::get($this->buildMonitorModuleCacheKey($supplier, $hostId));
        if (! is_array($cachedModules) || $cachedModules === []) {
            return [];
        }

        return $this->extractChartOptions($cachedModules);
    }

    private function fetchMonitorChart(
        Supplier $supplier,
        int $hostId,
        string $jwt,
        string $type,
        int $start,
        int $end,
        bool $fresh = false
    ): array {
        $cachedChart = $fresh ? null : $this->getCachedMonitorChart($supplier, $hostId, $type, $start, $end);
        if (! $fresh && is_array($cachedChart)) {
            Log::info('[客户端监控] 单图缓存命中', [
                'supplier_id' => $supplier->id,
                'host_id' => $hostId,
                'type' => $type,
            ]);

            return $cachedChart;
        }

        $response = $this->fetchMonitorChartResponse($supplier, $hostId, $jwt, [
            'type' => $type,
            'start' => $start,
            'end' => $end,
        ]);
        $this->assertSuccess($response, '读取监控图表');

        $chart = $this->normalizeMonitorChartData($this->extractPayload($response), $type, $start, $end);
        $payload = $this->buildMonitorChartPayload($chart, $start, $end);
        if (! $fresh) {
            $this->putMonitorChartCache($supplier, $hostId, $type, $start, $end, $payload);
        }

        return $payload;
    }

    private function fetchMonitorChartsBatch(
        Supplier $supplier,
        int $hostId,
        string $jwt,
        array $types,
        array $range,
        array $chartOptions = [],
        bool $fresh = false
    ): array {
        $cachedCharts = [];
        $missingTypes = [];

        if (! $fresh) {
            $cachedCharts = $this->getCachedMonitorChartsMap($supplier, $hostId, $types, $range['start'], $range['end']);
            $missingTypes = array_values(array_filter(
                $types,
                fn (string $type) => ! is_array($cachedCharts[$type] ?? null)
            ));
        } else {
            $missingTypes = $types;
        }

        if ($cachedCharts !== []) {
            Log::info('[客户端监控] 批量图表缓存命中', [
                'supplier_id' => $supplier->id,
                'host_id' => $hostId,
                'hit_types' => array_keys($cachedCharts),
                'miss_types' => $missingTypes,
            ]);
        }

        $responses = [];
        if ($missingTypes !== []) {
            $responses = $this->fetchMonitorChartResponses(
                $supplier,
                $hostId,
                collect($missingTypes)->mapWithKeys(fn (string $type) => [
                    $type => [
                        'type' => $type,
                        'start' => $range['start'],
                        'end' => $range['end'],
                    ],
                ])->all(),
                $jwt
            );
        }

        return collect($types)->map(function (string $type) use ($supplier, $hostId, $responses, $cachedCharts, $range, $chartOptions, $fresh) {
            $label = $this->resolveChartOptionLabel($type, $chartOptions, $type);

            if (is_array($cachedCharts[$type] ?? null)) {
                $chart = $cachedCharts[$type];
                $chartData = is_array($chart['chart'] ?? null) ? $chart['chart'] : null;
                $summary = is_array($chart['summary'] ?? null) ? $chart['summary'] : null;

                return [
                    'type' => $type,
                    'label' => $this->resolveChartOptionLabel($type, $chartOptions, $label),
                    'message' => is_array($chartData) && ($chartData['list'] ?? []) !== [] ? '' : '当前时间范围内暂无监控数据',
                    'error' => '',
                    'chart' => $chartData,
                    'summary' => $summary,
                ];
            }

            $response = $responses[$type] ?? null;

            if (! is_array($response) || ! is_array($response['response'] ?? null)) {
                return [
                    'type' => $type,
                    'label' => $label,
                    'message' => '',
                    'error' => is_array($response) ? (string) ($response['error'] ?? '监控图表加载失败') : '监控图表加载失败',
                    'chart' => null,
                    'summary' => null,
                ];
            }

            try {
                $payload = $response['response'];
                $this->assertSuccess($payload, '读取监控图表');
                $chart = $this->normalizeMonitorChartData($this->extractPayload($payload), $type, $range['start'], $range['end']);
                $chartPayload = $this->buildMonitorChartPayload($chart, $range['start'], $range['end']);
                if (! $fresh) {
                    $this->putMonitorChartCache($supplier, $hostId, $type, $range['start'], $range['end'], $chartPayload);
                }
                $chartData = is_array($chartPayload['chart'] ?? null) ? $chartPayload['chart'] : null;
                $summary = is_array($chartPayload['summary'] ?? null) ? $chartPayload['summary'] : null;

                return [
                    'type' => $type,
                    'label' => $this->resolveChartOptionLabel($type, $chartOptions, $label),
                    'message' => is_array($chartData) && ($chartData['list'] ?? []) !== [] ? '' : '当前时间范围内暂无监控数据',
                    'error' => '',
                    'chart' => $chartData,
                    'summary' => $summary,
                ];
            } catch (\Throwable $exception) {
                return [
                    'type' => $type,
                    'label' => $label,
                    'message' => '',
                    'error' => $exception->getMessage(),
                    'chart' => null,
                    'summary' => null,
                ];
            }
        })->values()->all();
    }

    // ── 监控专用 constants (由 trait 内部使用) ────────────────────────────

    private const MONITOR_MAX_POINTS_SHORT = 36;

    private const MONITOR_MAX_POINTS_DAY = 48;

    private const MONITOR_MAX_POINTS_WEEK = 56;

    private const MONITOR_MAX_POINTS_LONG = 72;

    private const MONITOR_CACHE_SCHEMA_VERSION = 'v4';

    private const MONITOR_CHART_CACHE_TTL_SECONDS = 600;

    private const MONITOR_CACHE_BUCKET_MS = 300000;

    // ── 监控辅助私有方法 ───────────────────────────────────────────────────

    private function buildEmptyMonitorResponse(array $range, string $message, array $options = []): array
    {
        return [
            'supported' => false,
            'message' => $message,
            'error' => '',
            'options' => $options,
            'selected_type' => '',
            'selected_label' => '',
            'range' => $range,
            'chart' => null,
            'summary' => null,
        ];
    }

    private function resolveMonitorRange(array $filters): array
    {
        $start = isset($filters['start']) ? $this->normalizeMonitorRangeTimestamp($filters['start']) : null;
        $end = isset($filters['end']) ? $this->normalizeMonitorRangeTimestamp($filters['end']) : null;

        if ($start !== null && $end !== null) {
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            if ($start === $end) {
                $start = max($end - 86_400_000, 0);
            }

            return ['preset' => 'custom', 'start' => $start, 'end' => $end];
        }

        $preset = trim((string) ($filters['range'] ?? '3h'));
        $now = Carbon::now();
        $end = $this->normalizeMonitorRangeTimestamp($now->valueOf());
        $startAt = match ($preset) {
            '3h' => $now->copy()->subHours(3),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
            default => $now->copy()->subDay(),
        };

        return [
            'preset' => in_array($preset, ['3h', '24h', '7d', '30d'], true) ? $preset : '3h',
            'start' => $this->normalizeMonitorRangeTimestamp($startAt->valueOf()),
            'end' => $end,
        ];
    }

    private function normalizeMonitorChartData(array $payload, string $type, int $start, int $end): array
    {
        $unit = trim((string) ($payload['unit'] ?? ''));
        $chartType = trim((string) ($payload['chart_type'] ?? 'line'));
        $labels = $this->normalizeMonitorSeriesLabels($payload['label'] ?? null, $type);
        $rawSeriesList = $this->normalizeRawMonitorSeries($payload['list'] ?? null);
        $series = collect($rawSeriesList)
            ->map(function (array $items, int $index) use ($labels, $start, $end, $unit, $type) {
                $name = $this->normalizeMonitorDisplayLabel(trim((string) ($labels[$index] ?? $labels[0] ?? $type)) ?: $type);

                return [
                    'key' => $name !== '' ? $name : 'series_'.($index + 1),
                    'name' => $name,
                    'list' => $this->normalizeMonitorPointList($items, $start, $end, $unit),
                ];
            })
            ->filter(fn (array $item) => $item['list'] !== [])
            ->values()
            ->all();

        if ($series === [] && $rawSeriesList === [] && is_array($payload['list'] ?? null)) {
            $fallbackList = $this->normalizeMonitorPointList((array) $payload['list'], $start, $end, $unit);
            if ($fallbackList !== []) {
                $series[] = [
                    'key' => $this->normalizeMonitorDisplayLabel($labels[0] ?? $type) ?: 'series_1',
                    'name' => $this->normalizeMonitorDisplayLabel($labels[0] ?? $type),
                    'list' => $fallbackList,
                ];
            }
        }

        $series = $this->trimMonitorBoundaryPlaceholderPoints($series, $start, $end);

        // 内存类型图表：提取总量系列的峰值作为 Y 轴上限，然后过滤掉总量线
        $yMax = null;
        if ($this->isMemoryMonitorType($type)) {
            foreach ($series as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if (in_array($name, ['最大值', '最大内存', 'total', 'max'], true)) {
                    foreach ((array) ($item['list'] ?? []) as $point) {
                        $pointValue = $point['value'] ?? null;
                        if (is_numeric($pointValue)) {
                            $yMax = $yMax === null ? (float) $pointValue : max($yMax, (float) $pointValue);
                        }
                    }
                }
            }
            $series = array_values(array_filter($series, function (array $item) {
                $name = trim((string) ($item['name'] ?? ''));

                return ! in_array($name, ['最大值', '最大内存', 'total', 'max'], true);
            }));
        }

        $primarySeries = collect($series)->sortByDesc(
            fn (array $item) => ($this->resolveMonitorSeriesPriority((string) ($item['name'] ?? '')) * 1000) + count($item['list'] ?? [])
        )->first();
        $primaryList = is_array($primarySeries['list'] ?? null) ? $primarySeries['list'] : [];

        return [
            'type' => $type,
            'chart_type' => $chartType !== '' ? $chartType : 'line',
            'unit' => $unit,
            'y_max' => $yMax,
            'series' => $series,
            'list' => $primaryList,
        ];
    }

    private function resolveMonitorSeriesPriority(string $name): int
    {
        return match (trim($name)) {
            '当前值' => 60,
            '读取' => 55,
            '流入' => 50,
            '最大值' => 45,
            '写入' => 40,
            '流出' => 35,
            default => 10,
        };
    }

    private function normalizeMonitorSeriesLabels(mixed $labels, string $fallback): array
    {
        if (is_array($labels)) {
            $normalized = collect($labels)
                ->map(fn ($item) => $this->normalizeMonitorDisplayLabel(trim((string) $item)))
                ->filter(fn (string $item) => $item !== '')
                ->values()
                ->all();

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $label = trim((string) $labels);

        return [$this->normalizeMonitorDisplayLabel($label !== '' ? $label : $fallback)];
    }

    private function normalizeRawMonitorSeries(mixed $list): array
    {
        if (! is_array($list) || $list === []) {
            return [];
        }

        $first = $list[array_key_first($list)] ?? null;
        if ($this->isMonitorPointItem($first)) {
            return [array_values(array_filter($list, fn ($item) => is_array($item)))];
        }

        return collect($list)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                if ($this->isMonitorPointItem($item)) {
                    return [$item];
                }

                return array_values(array_filter($item, fn ($point) => is_array($point)));
            })
            ->filter(fn (array $item) => $item !== [])
            ->values()
            ->all();
    }

    private function normalizeMonitorPointList(array $items, int $start, int $end, string $unit): array
    {
        return collect($items)
            ->map(function ($item) use ($start, $end, $unit) {
                if (! is_array($item)) {
                    return null;
                }

                $rawTime = $item['time'] ?? $item[0] ?? null;
                $rawValue = $item['value'] ?? $item[1] ?? null;
                $timestamp = $this->parseMonitorTimestamp($rawTime);
                $value = $this->parseMonitorValue($rawValue);
                $displayValue = is_numeric($rawValue)
                    ? $this->formatMonitorValue($value, $unit)
                    : trim((string) ($rawValue ?? ($value ?? '')));

                return [
                    'time' => $this->formatMonitorPointLabel($rawTime, $timestamp, $start, $end),
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'display_value' => $displayValue !== '' ? $displayValue : '--',
                ];
            })
            ->filter(fn ($item) => is_array($item) && $item['time'] !== '')
            ->sortBy(fn (array $item) => $item['timestamp'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    private function trimMonitorBoundaryPlaceholderPoints(array $series, int $start, int $end): array
    {
        $normalizedSeries = collect($series)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                return [
                    'key' => (string) ($item['key'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'list' => array_values(array_filter((array) ($item['list'] ?? []), fn ($point) => is_array($point))),
                ];
            })
            ->filter(fn (array $item) => $item['list'] !== [])
            ->values()
            ->all();

        if ($normalizedSeries === []) {
            return [];
        }

        $toleranceMs = $this->resolveMonitorBoundaryToleranceMs($normalizedSeries);
        $headTimestamp = $this->findMonitorBoundaryTimestamp($normalizedSeries, true);

        if ($this->shouldTrimMonitorBoundaryTimestamp($headTimestamp, $normalizedSeries, $start, $end, $toleranceMs, true)) {
            $normalizedSeries = $this->removeMonitorBoundaryTimestamp($normalizedSeries, $headTimestamp);
        }

        $tailTimestamp = $this->findMonitorBoundaryTimestamp($normalizedSeries, false);

        if ($this->shouldTrimMonitorBoundaryTimestamp($tailTimestamp, $normalizedSeries, $start, $end, $toleranceMs, false)) {
            $normalizedSeries = $this->removeMonitorBoundaryTimestamp($normalizedSeries, $tailTimestamp);
        }

        return array_values(array_filter($normalizedSeries, fn (array $item) => $item['list'] !== []));
    }

    private function resolveMonitorBoundaryToleranceMs(array $series): int
    {
        $timestamps = collect($series)
            ->flatMap(fn (array $item) => collect((array) ($item['list'] ?? []))
                ->map(fn (array $point) => $this->extractMonitorPointTimestamp($point))
                ->filter(fn ($timestamp) => $timestamp !== null)
            )
            ->unique()
            ->sort()
            ->values();

        if ($timestamps->count() < 2) {
            return self::MONITOR_CACHE_BUCKET_MS;
        }

        $minGap = null;

        for ($index = 1; $index < $timestamps->count(); $index++) {
            $gap = (int) $timestamps[$index] - (int) $timestamps[$index - 1];

            if ($gap <= 0) {
                continue;
            }

            $minGap = $minGap === null ? $gap : min($minGap, $gap);
        }

        if ($minGap === null) {
            return self::MONITOR_CACHE_BUCKET_MS;
        }

        return max(self::MONITOR_CACHE_BUCKET_MS, min($minGap * 2, 30 * 60 * 1000));
    }

    private function findMonitorBoundaryTimestamp(array $series, bool $isHead): ?int
    {
        $timestamps = collect($series)
            ->flatMap(fn (array $item) => collect((array) ($item['list'] ?? []))
                ->map(fn (array $point) => $this->extractMonitorPointTimestamp($point))
                ->filter(fn ($timestamp) => $timestamp !== null)
            )
            ->values()
            ->all();

        if ($timestamps === []) {
            return null;
        }

        return $isHead ? min($timestamps) : max($timestamps);
    }

    private function shouldTrimMonitorBoundaryTimestamp(?int $boundaryTimestamp, array $series, int $start, int $end, int $toleranceMs, bool $isHead): bool
    {
        if ($boundaryTimestamp === null) {
            return false;
        }

        if ($isHead && $boundaryTimestamp > ($start + $toleranceMs)) {
            return false;
        }

        if (! $isHead && $boundaryTimestamp < ($end - $toleranceMs)) {
            return false;
        }

        $boundaryPoints = [];
        $hasNonZeroOutsideBoundary = false;
        $hasNonZeroNeighbor = false;

        foreach ($series as $item) {
            $points = array_values(array_filter((array) ($item['list'] ?? []), fn ($point) => is_array($point)));
            $boundaryIndex = null;

            foreach ($points as $index => $point) {
                $timestamp = $this->extractMonitorPointTimestamp($point);
                $value = $point['value'] ?? null;

                if ($timestamp === $boundaryTimestamp) {
                    $boundaryPoints[] = $point;
                    $boundaryIndex = $index;

                    continue;
                }

                if (is_numeric($value) && abs((float) $value) > 0.000001) {
                    $hasNonZeroOutsideBoundary = true;
                }
            }

            if ($boundaryIndex === null) {
                continue;
            }

            $neighborIndex = $isHead ? $boundaryIndex + 1 : $boundaryIndex - 1;
            $neighbor = $points[$neighborIndex] ?? null;

            if (! is_array($neighbor)) {
                continue;
            }

            $neighborTimestamp = $this->extractMonitorPointTimestamp($neighbor);
            $neighborValue = $neighbor['value'] ?? null;

            if ($neighborTimestamp === null || ! is_numeric($neighborValue)) {
                continue;
            }

            if (abs($neighborTimestamp - $boundaryTimestamp) <= ($toleranceMs * 2) && abs((float) $neighborValue) > 0.000001) {
                $hasNonZeroNeighbor = true;
            }
        }

        if ($boundaryPoints === [] || ! $hasNonZeroOutsideBoundary || ! $hasNonZeroNeighbor) {
            return false;
        }

        foreach ($boundaryPoints as $point) {
            $value = $point['value'] ?? null;

            if (! is_numeric($value) || abs((float) $value) > 0.000001) {
                return false;
            }
        }

        return true;
    }

    private function removeMonitorBoundaryTimestamp(array $series, int $boundaryTimestamp): array
    {
        return collect($series)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($boundaryTimestamp) {
                $item['list'] = array_values(array_filter(
                    (array) ($item['list'] ?? []),
                    fn ($point) => is_array($point) && $this->extractMonitorPointTimestamp($point) !== $boundaryTimestamp
                ));

                return $item;
            })
            ->filter(fn (array $item) => $item['list'] !== [])
            ->values()
            ->all();
    }

    private function extractMonitorPointTimestamp(array $point): ?int
    {
        $timestamp = $point['timestamp'] ?? null;

        if (! is_numeric($timestamp)) {
            return null;
        }

        return (int) round((float) $timestamp);
    }

    private function isMonitorPointItem(mixed $item): bool
    {
        if (! is_array($item) || $item === []) {
            return false;
        }

        if (array_key_exists('time', $item) || array_key_exists('value', $item)) {
            return true;
        }

        return array_key_exists(0, $item) && ! is_array($item[0]);
    }

    private function buildMonitorSummary(array $chart): ?array
    {
        $unit = (string) ($chart['unit'] ?? '');
        $primarySummary = $this->buildMonitorSeriesSummary(['name' => '', 'list' => (array) ($chart['list'] ?? [])], $unit);
        $seriesSummary = collect((array) ($chart['series'] ?? []))
            ->map(fn (array $series) => $this->buildMonitorSeriesSummary($series, $unit))
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();

        if (! is_array($primarySummary) && $seriesSummary === []) {
            return null;
        }

        $summary = is_array($primarySummary)
            ? $primarySummary
            : (is_array($seriesSummary[0] ?? null) ? $seriesSummary[0] : null);

        if (! is_array($summary)) {
            return null;
        }

        if ($seriesSummary !== []) {
            $summary['series'] = $seriesSummary;
        }

        return $summary;
    }

    private function buildMonitorSeriesSummary(array $series, string $unit): ?array
    {
        $list = collect($series['list'] ?? [])
            ->filter(fn ($item) => is_array($item) && $item['value'] !== null)
            ->values();

        if ($list->isEmpty()) {
            return null;
        }

        $values = $list->pluck('value')->filter(fn ($value) => $value !== null)->values();
        $latest = $list->last();
        $latestValue = $latest['value'] ?? null;
        $label = trim((string) ($series['name'] ?? ''));
        $key = trim((string) ($series['key'] ?? $label));

        return [
            'key' => $key !== '' ? $key : 'series',
            'label' => $label,
            'latest' => ['value' => $latestValue, 'text' => $this->formatMonitorValue($latestValue, $unit), 'time' => (string) ($latest['time'] ?? '')],
            'average' => ['value' => $values->avg(),  'text' => $this->formatMonitorValue($values->avg(), $unit)],
            'peak' => ['value' => $values->max(),  'text' => $this->formatMonitorValue($values->max(), $unit)],
            'lowest' => ['value' => $values->min(),  'text' => $this->formatMonitorValue($values->min(), $unit)],
            'sample_count' => $values->count(),
        ];
    }

    private function resolveMonitorPointLimit(int $start, int $end): int
    {
        $duration = max($end - $start, 0);

        return match (true) {
            $duration <= 3 * 60 * 60 * 1000 => self::MONITOR_MAX_POINTS_SHORT,
            $duration <= 24 * 60 * 60 * 1000 => self::MONITOR_MAX_POINTS_DAY,
            $duration <= 7 * 24 * 60 * 60 * 1000 => self::MONITOR_MAX_POINTS_WEEK,
            default => self::MONITOR_MAX_POINTS_LONG,
        };
    }

    private function buildMonitorChartPayload(array $chart, int $start, int $end): array
    {
        $pointLimit = $this->resolveMonitorPointLimit($start, $end);

        $yMax = isset($chart['y_max']) && is_numeric($chart['y_max']) ? (float) $chart['y_max'] : null;

        return [
            'chart' => [
                'type' => (string) ($chart['type'] ?? ''),
                'chart_type' => (string) ($chart['chart_type'] ?? 'line'),
                'unit' => (string) ($chart['unit'] ?? ''),
                'y_max' => $yMax,
                'list' => $this->compactMonitorPointList((array) ($chart['list'] ?? []), $pointLimit),
                'series' => $this->compactMonitorSeriesList((array) ($chart['series'] ?? []), $pointLimit),
            ],
            'summary' => $this->buildMonitorSummary($chart),
        ];
    }

    private function compactMonitorSeriesList(array $series, int $maxPoints): array
    {
        return collect($series)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($maxPoints) {
                return [
                    'key' => (string) ($item['key'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'list' => $this->compactMonitorPointList((array) ($item['list'] ?? []), $maxPoints),
                ];
            })
            ->filter(fn (array $item) => $item['list'] !== [])
            ->values()
            ->all();
    }

    private function compactMonitorPointList(array $list, int $maxPoints): array
    {
        $points = array_values(array_filter($list, fn ($item) => is_array($item)));
        $count = count($points);

        if ($count <= $maxPoints || $maxPoints < 3) {
            return $points;
        }

        $pickedIndexes = [0 => true, $count - 1 => true];
        $minIndex = $maxIndex = null;
        $minValue = $maxValue = null;

        foreach ($points as $index => $point) {
            $value = $point['value'] ?? null;
            if (! is_numeric($value)) {
                continue;
            }

            $numericValue = (float) $value;

            if ($minValue === null || $numericValue < $minValue) {
                $minValue = $numericValue;
                $minIndex = $index;
            }

            if ($maxValue === null || $numericValue > $maxValue) {
                $maxValue = $numericValue;
                $maxIndex = $index;
            }
        }

        if ($minIndex !== null) {
            $pickedIndexes[$minIndex] = true;
        }

        if ($maxIndex !== null) {
            $pickedIndexes[$maxIndex] = true;
        }

        $remainingSlots = $maxPoints - count($pickedIndexes);
        if ($remainingSlots > 0) {
            $step = ($count - 1) / ($remainingSlots + 1);
            for ($position = 1; $position <= $remainingSlots; $position++) {
                $index = (int) round($position * $step);
                $pickedIndexes[min(max($index, 0), $count - 1)] = true;
            }
        }

        if (count($pickedIndexes) < $maxPoints) {
            $stride = max((int) floor(($count - 1) / max($maxPoints - 1, 1)), 1);
            for ($index = 0; $index < $count && count($pickedIndexes) < $maxPoints; $index += $stride) {
                $pickedIndexes[$index] = true;
            }
        }

        ksort($pickedIndexes);

        return array_values(array_map(fn (int $index) => $points[$index], array_keys($pickedIndexes)));
    }

    private function parseMonitorValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', str_replace(',', '', $text), $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    private function parseMonitorTimestamp(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $timestamp = $this->normalizeMonitorRangeTimestamp($value);

            if ($timestamp >= 1_000_000_000_000) {
                return $timestamp;
            }

            if ($timestamp >= 1_000_000_000) {
                return $timestamp * 1000;
            }
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return $this->normalizeMonitorRangeTimestamp(Carbon::parse($text)->valueOf());
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeMonitorRangeTimestamp(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $timestamp = max((int) round((float) $value), 0);

        if ($timestamp >= 1_000_000_000_000) {
            return $timestamp;
        }

        if ($timestamp >= 1_000_000_000) {
            return $timestamp * 1000;
        }

        return $timestamp;
    }

    private function formatMonitorPointLabel(mixed $rawTime, ?int $timestamp, int $start, int $end): string
    {
        if ($timestamp === null) {
            return trim((string) $rawTime);
        }

        $range = max($end - $start, 0);
        $format = match (true) {
            $range <= 86_400_000 => 'H:i',
            $range <= 7 * 86_400_000 => 'm-d H:i',
            default => 'm-d',
        };

        return Carbon::createFromTimestampMs($timestamp, config('app.timezone'))->format($format);
    }

    private function formatMonitorValue(mixed $value, string $unit = ''): string
    {
        if (! is_numeric($value)) {
            return '--';
        }

        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        if ($unit === '') {
            return $number;
        }

        return in_array($unit, ['%', 'ms'], true) ? $number.$unit : $number.' '.$unit;
    }

    private function normalizeMonitorDisplayLabel(string $label): string
    {
        $text = trim($label);
        $haystack = mb_strtolower($text);

        if ($haystack === '') {
            return '';
        }

        if (str_contains($haystack, '读取') || str_contains($haystack, 'read') || str_contains($haystack, 'rx')) {
            return '读取';
        }

        if (str_contains($haystack, '写入') || str_contains($haystack, 'write') || str_contains($haystack, 'tx')) {
            return '写入';
        }

        if (
            (str_contains($haystack, '总量') || str_contains($haystack, '最大') || str_contains($haystack, 'total') || str_contains($haystack, 'max'))
            && (str_contains($haystack, 'mem') || str_contains($haystack, 'ram') || str_contains($haystack, '内存') || str_contains($haystack, 'gb'))
        ) {
            return '最大值';
        }

        if (
            (str_contains($haystack, '已用') || str_contains($haystack, '使用') || str_contains($haystack, '当前') || str_contains($haystack, 'used') || str_contains($haystack, 'current'))
            && (str_contains($haystack, 'mem') || str_contains($haystack, 'ram') || str_contains($haystack, '内存') || str_contains($haystack, 'gb'))
        ) {
            return '当前值';
        }

        if (str_contains($haystack, '流入') || str_contains($haystack, '进(') || str_contains($haystack, '进带宽') || str_contains($haystack, 'in(') || str_contains($haystack, 'in_') || str_contains($haystack, 'in ')) {
            return '流入';
        }

        if (str_contains($haystack, '流出') || str_contains($haystack, '出(') || str_contains($haystack, '出带宽') || str_contains($haystack, 'out(') || str_contains($haystack, 'out_') || str_contains($haystack, 'out ')) {
            return '流出';
        }

        if (str_contains($haystack, 'cpu') || str_contains($haystack, '处理器')) {
            return 'cpu';
        }

        if (str_contains($haystack, 'disk') || str_contains($haystack, 'io') || str_contains($haystack, '磁盘') || str_contains($haystack, '硬盘')) {
            return '硬盘IO';
        }

        if ((str_contains($haystack, '已用') || str_contains($haystack, '使用')) && (str_contains($haystack, 'mem') || str_contains($haystack, 'ram') || str_contains($haystack, '内存'))) {
            return '已用内存';
        }

        if (str_contains($haystack, 'mem') || str_contains($haystack, 'ram') || str_contains($haystack, '内存') || (str_contains($haystack, '总量') && (str_contains($haystack, 'mb') || str_contains($haystack, 'gb')))) {
            return '内存';
        }

        if (str_contains($haystack, 'net') || str_contains($haystack, 'bw') || str_contains($haystack, 'bandwidth') || str_contains($haystack, 'network') || str_contains($haystack, '网卡') || str_contains($haystack, '网络') || str_contains($haystack, '带宽') || str_contains($haystack, '宽带')) {
            return '宽带';
        }

        return $text;
    }

    private function isMemoryMonitorType(string $type): bool
    {
        $haystack = mb_strtolower(trim($type));

        return str_contains($haystack, 'mem') || str_contains($haystack, 'ram') || str_contains($haystack, '内存');
    }

    private function extractChartOptions(array $modules): array
    {
        $chartModule = collect($modules)->first(function ($item) {
            if (! is_array($item)) {
                return false;
            }

            $function = trim((string) ($item['function'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));

            return $function === 'charts' || $name === '图表';
        });

        if (! is_array($chartModule)) {
            return [];
        }

        return $this->normalizeChartOptionList($chartModule['select'] ?? []);
    }

    private function normalizeChartOptionList(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $key => $option) {
            $value = '';
            $label = '';

            if (is_array($option)) {
                $value = trim((string) ($option['value'] ?? $option['key'] ?? $option['type'] ?? $option['id'] ?? (is_string($key) ? $key : '')));
                $label = trim((string) ($option['label'] ?? $option['name'] ?? $option['title'] ?? $option['text'] ?? $option['value'] ?? $value));
            } else {
                $optionText = trim((string) $option);
                if (is_string($key) && ! is_numeric($key)) {
                    $value = trim($key);
                    $label = $optionText !== '' ? $optionText : $value;
                } else {
                    $value = $optionText;
                    $label = $optionText;
                }
            }

            if ($value === '') {
                continue;
            }

            $normalized[$value] = [
                'value' => $value,
                'label' => $this->normalizeMonitorDisplayLabel($label !== '' ? $label : $value),
            ];
        }

        return array_values($normalized);
    }

    private function resolveChartOptionLabel(string $selectedType, array $options, string $fallback = ''): string
    {
        $match = collect($options)->first(
            fn (array $item) => (string) ($item['value'] ?? '') === $selectedType
        );

        $label = '';
        if (is_array($match) && trim((string) ($match['label'] ?? '')) !== '') {
            $label = (string) $match['label'];
        } elseif ($fallback !== '') {
            $label = $fallback;
        } else {
            $label = $selectedType;
        }

        return $this->normalizeMonitorDisplayLabel($label);
    }

    private function buildMonitorResponseCacheKey(Service $service, array $payload): string
    {
        $payload = $this->normalizeMonitorResponseCachePayload($payload);
        ksort($payload);

        return 'service_console:monitor:'.self::MONITOR_CACHE_SCHEMA_VERSION.':'.$service->id.':'.optional($service->updated_at)?->timestamp.':'.md5(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function buildMonitorBatchResponseCacheKey(Service $service, array $payload): string
    {
        $payload = $this->normalizeMonitorResponseCachePayload($payload);
        if (is_array($payload['types'] ?? null)) {
            sort($payload['types']);
        }
        ksort($payload);

        return 'service_console:monitor_batch:'.self::MONITOR_CACHE_SCHEMA_VERSION.':'.$service->id.':'.optional($service->updated_at)?->timestamp.':'.md5(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function buildMonitorChartCacheKey(Supplier $supplier, int $hostId, string $type, int $start, int $end): string
    {
        $normalizedRange = $this->normalizeMonitorCacheRange($start, $end);
        $providerKey = app(PluginBindingResolver::class)->providerKeyForSupplier($supplier);
        $providerKey = trim((string) $providerKey) !== '' ? trim((string) $providerKey) : 'unbound';

        return "upstream:{$providerKey}:host_chart:".self::MONITOR_CACHE_SCHEMA_VERSION.":{$supplier->id}:{$hostId}:{$type}:{$normalizedRange['start']}:{$normalizedRange['end']}";
    }

    private function hasMonitorSupplierBindingTable(): bool
    {
        try {
            return Schema::hasTable('supplier_plugin_bindings');
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeMonitorResponseCachePayload(array $payload): array
    {
        if (! is_array($payload['range'] ?? null)) {
            return $payload;
        }

        $normalizedRange = $this->normalizeMonitorCacheRange(
            (int) ($payload['range']['start'] ?? 0),
            (int) ($payload['range']['end'] ?? 0)
        );

        $payload['range']['start'] = $normalizedRange['start'];
        $payload['range']['end'] = $normalizedRange['end'];

        return $payload;
    }

    private function normalizeMonitorCacheRange(int $start, int $end): array
    {
        return [
            'start' => $this->normalizeMonitorCacheTimestamp($start),
            'end' => $this->normalizeMonitorCacheTimestamp($end),
        ];
    }

    private function normalizeMonitorCacheTimestamp(int $timestamp): int
    {
        if ($timestamp <= 0) {
            return 0;
        }

        return intdiv($timestamp, self::MONITOR_CACHE_BUCKET_MS) * self::MONITOR_CACHE_BUCKET_MS;
    }

    private function getCachedMonitorChart(Supplier $supplier, int $hostId, string $type, int $start, int $end): ?array
    {
        $cacheKeys = $this->buildMonitorChartCacheLookupKeys($supplier, $hostId, $type, $start, $end);
        $cachedValues = Cache::many($cacheKeys);

        foreach ($cacheKeys as $cacheKey) {
            $cached = $cachedValues[$cacheKey] ?? null;

            if (! is_array($cached) || ! is_array($cached['chart'] ?? null)) {
                continue;
            }

            return [
                'chart' => $cached['chart'],
                'summary' => is_array($cached['summary'] ?? null) ? $cached['summary'] : $this->buildMonitorSummary($cached['chart']),
            ];
        }

        return null;
    }

    private function getCachedMonitorChartsMap(Supplier $supplier, int $hostId, array $types, int $start, int $end): array
    {
        if ($types === []) {
            return [];
        }

        $keyMap = [];
        $allCacheKeys = [];
        foreach ($types as $type) {
            $lookupKeys = $this->buildMonitorChartCacheLookupKeys($supplier, $hostId, (string) $type, $start, $end);
            $keyMap[(string) $type] = $lookupKeys;
            $allCacheKeys = array_merge($allCacheKeys, $lookupKeys);
        }

        $cachedValues = Cache::many(array_values(array_unique($allCacheKeys)));
        $result = [];

        foreach ($keyMap as $type => $cacheKeys) {
            foreach ($cacheKeys as $cacheKey) {
                $cached = $cachedValues[$cacheKey] ?? null;
                if (! is_array($cached) || ! is_array($cached['chart'] ?? null)) {
                    continue;
                }

                $result[$type] = [
                    'chart' => $cached['chart'],
                    'summary' => is_array($cached['summary'] ?? null) ? $cached['summary'] : $this->buildMonitorSummary($cached['chart']),
                ];
                break;
            }
        }

        return $result;
    }

    private function buildMonitorChartCacheLookupKeys(Supplier $supplier, int $hostId, string $type, int $start, int $end): array
    {
        $ranges = [
            ['start' => $start, 'end' => $end],
        ];

        if ($start > self::MONITOR_CACHE_BUCKET_MS || $end > self::MONITOR_CACHE_BUCKET_MS) {
            $ranges[] = [
                'start' => max($start - self::MONITOR_CACHE_BUCKET_MS, 0),
                'end' => max($end - self::MONITOR_CACHE_BUCKET_MS, 0),
            ];
        }

        $keys = [];
        foreach ($ranges as $range) {
            foreach ([
                $this->buildMonitorChartCacheKey($supplier, $hostId, $type, $range['start'], $range['end']),
            ] as $cacheKey) {
                if (! in_array($cacheKey, $keys, true)) {
                    $keys[] = $cacheKey;
                }
            }
        }

        return $keys;
    }

    private function putMonitorChartCache(Supplier $supplier, int $hostId, string $type, int $start, int $end, array $chart): void
    {
        Cache::put(
            $this->buildMonitorChartCacheKey($supplier, $hostId, $type, $start, $end),
            $chart,
            now()->addSeconds(self::MONITOR_CHART_CACHE_TTL_SECONDS)
        );
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
