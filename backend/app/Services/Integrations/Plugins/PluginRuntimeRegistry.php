<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use App\Models\IntegrationPluginRuntimeLog;
use App\Services\Integrations\Plugins\Adapters\PluginMailDriver;
use App\Services\Integrations\Plugins\Adapters\PluginPaymentGateway;
use App\Services\Integrations\Plugins\Adapters\PluginSmsDriver;
use App\Services\Integrations\Plugins\Adapters\PluginUpstreamDriver;
use App\Services\Integrations\Plugins\Adapters\PluginVerificationDriver;
use App\Services\Mail\Contracts\MailDriver;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PluginRuntimeRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly PluginScanner $scanner,
        private readonly PluginFileLoader $fileLoader,
        private readonly PluginConfigRepository $configRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(string $domain, string $slugOrKey, string $action, array $payload = [], array $context = []): array
    {
        $resolvedDomain = PluginDomain::assertValid($domain);
        $resolvedIdentifier = trim($slugOrKey);
        $resolvedAction = trim($action);

        if ($resolvedIdentifier === '' || $resolvedAction === '') {
            throw new BusinessException('插件或执行动作不能为空', 42200);
        }

        if (! Schema::hasTable('integration_plugins')) {
            throw new BusinessException('插件系统尚未初始化', 42200);
        }

        $plugin = IntegrationPlugin::query()
            ->where('domain', $resolvedDomain)
            ->where(static function ($query) use ($resolvedIdentifier): void {
                $query->where('slug', $resolvedIdentifier)
                    ->orWhere('plugin_key', $resolvedIdentifier);
            })
            ->first();

        if (! $plugin instanceof IntegrationPlugin || ! $plugin->isEnabled()) {
            throw new BusinessException('插件未安装或未启用', 42200);
        }

        return $this->executePlugin($plugin, $resolvedAction, $payload, $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function executePlugin(IntegrationPlugin $plugin, string $resolvedAction, array $payload = [], array $context = []): array
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $entry = $this->makeExecutableEntry($manifest);
        $startedAt = microtime(true);
        $traceId = $this->resolveTraceId($context);
        $request = [
            'domain' => $manifest->domain,
            'slug' => $manifest->slug,
            'key' => $manifest->key,
            'action' => $resolvedAction,
            'payload' => $payload,
            'config' => $this->configRepository->resolvedConfig($plugin),
            'context' => array_merge($context, ['trace_id' => $traceId]),
        ];

        try {
            $raw = $entry->execute($request);
            $result = is_array($raw) ? $raw : ['data' => $raw];
            $response = array_merge([
                'success' => (bool) ($result['success'] ?? true),
                'action' => $resolvedAction,
                'plugin' => [
                    'domain' => $manifest->domain,
                    'slug' => $manifest->slug,
                    'key' => $manifest->key,
                    'name' => $manifest->name,
                ],
                'message' => (string) ($result['message'] ?? ''),
                'data' => $result['data'] ?? [],
                'raw' => $result['raw'] ?? [],
            ], $result);

            $this->recordRuntimeLog($plugin, $manifest, $resolvedAction, $payload, $context, $response, 'success', $startedAt, $traceId);

            return $response;
        } catch (BusinessException $exception) {
            $this->recordRuntimeLog($plugin, $manifest, $resolvedAction, $payload, $context, null, 'failed', $startedAt, $traceId, $exception);
            Log::warning('[plugins] business exception', [
                'domain' => $manifest->domain,
                'slug' => $manifest->slug,
                'action' => $resolvedAction,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->recordRuntimeLog($plugin, $manifest, $resolvedAction, $payload, $context, null, 'failed', $startedAt, $traceId, $exception);
            Log::error('[plugins] execute failed', [
                'domain' => $manifest->domain,
                'slug' => $manifest->slug,
                'action' => $resolvedAction,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new BusinessException('插件执行失败', 42200);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $response
     */
    private function recordRuntimeLog(
        IntegrationPlugin $plugin,
        PluginManifest $manifest,
        string $action,
        array $payload,
        array $context,
        ?array $response,
        string $status,
        float $startedAt,
        string $traceId,
        ?\Throwable $exception = null,
    ): void {
        if (! Schema::hasTable('integration_plugin_runtime_logs')) {
            return;
        }

        try {
            IntegrationPluginRuntimeLog::query()->create([
                'trace_id' => $traceId !== '' ? $traceId : null,
                'domain' => $manifest->domain,
                'plugin_id' => (int) $plugin->id,
                'plugin_key' => $manifest->key,
                'slug' => $manifest->slug,
                'action' => $action,
                'binding_id' => $this->positiveInt($context['binding_id'] ?? null),
                'bindable_type' => $this->nullableString($context['bindable_type'] ?? null, 120),
                'bindable_id' => $this->positiveInt($context['bindable_id'] ?? null),
                'actor_type' => $this->nullableString($context['actor_type'] ?? null, 50),
                'actor_id' => $this->positiveInt($context['actor_id'] ?? null),
                'status' => $status,
                'duration_ms' => (int) max(0, round((microtime(true) - $startedAt) * 1000)),
                'error_code' => $exception === null ? null : $this->nullableString((string) $exception->getCode(), 80),
                'error_message' => $exception === null ? null : $this->nullableString($exception->getMessage(), 500),
                'request_meta_json' => $this->runtimeMeta([
                    'payload' => $payload,
                    'context' => $context,
                ]),
                'response_meta_json' => $response === null ? null : $this->runtimeMeta($response),
                'created_at' => now(),
            ]);
        } catch (\Throwable $logException) {
            Log::warning('[plugins] runtime log write failed', [
                'domain' => $manifest->domain,
                'slug' => $manifest->slug,
                'action' => $action,
                'message' => $logException->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTraceId(array $context): string
    {
        $traceId = trim((string) ($context['trace_id'] ?? ''));

        return $traceId !== ''
            ? substr($traceId, 0, 64)
            : substr('plugin:'.str_replace('-', '', (string) Str::uuid()), 0, 64);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runtimeMeta(array $payload): array
    {
        $sanitized = SensitiveDataSanitizer::sanitize($payload);

        return is_array($sanitized) ? $sanitized : [];
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = (int) ($value ?? 0);

        return $int > 0 ? $int : null;
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return null;
        }

        return mb_substr($string, 0, $limit);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return array<int, T>
     */
    public function resolveEntries(string $domain, string $contract): array
    {
        $resolvedDomain = PluginDomain::assertValid($domain);

        if (! Schema::hasTable('integration_plugins')) {
            return [];
        }

        $plugins = IntegrationPlugin::query()
            ->where('domain', $resolvedDomain)
            ->orderByDesc('status')
            ->orderBy('name')
            ->get();

        if ($plugins->isEmpty()) {
            return [];
        }

        $entries = [];
        $resolvedKeys = [];

        foreach ($plugins as $plugin) {
            if (! $plugin->isEnabled()) {
                continue;
            }

            $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
            if (! $manifest instanceof PluginManifest) {
                Log::error('[plugins] enabled plugin manifest missing — plugin is enabled but files not found', [
                    'domain' => $plugin->domain,
                    'slug' => $plugin->slug,
                    'plugin_id' => $plugin->id,
                ]);

                continue;
            }

            $instance = $this->makeAdapter($manifest, $contract);
            if ($instance !== null) {
                $entryKey = $this->resolveEntryKey($instance);
                if ($entryKey !== null && isset($resolvedKeys[$entryKey])) {
                    Log::warning('[plugins] duplicate enabled plugin key skipped', [
                        'domain' => $manifest->domain,
                        'slug' => $manifest->slug,
                        'entry_key' => $entryKey,
                    ]);

                    continue;
                }

                $entries[] = $instance;
                if ($entryKey !== null) {
                    $resolvedKeys[$entryKey] = true;
                }
            }
        }

        return $entries;
    }

    public function healthCheck(IntegrationPlugin $plugin): array
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $entry = $this->makeExecutableEntry($manifest);

        $result = [
            'healthy' => true,
            'message' => '插件加载正常',
            'entry_class' => $manifest->entryClass,
            'provider_class' => $manifest->providerClass,
            'details' => [],
        ];

        if (method_exists($entry, 'healthCheck')) {
            $raw = $entry->healthCheck();
            if (is_array($raw)) {
                if (isset($raw['healthy'])) {
                    $result['healthy'] = (bool) $raw['healthy'];
                }
                if (isset($raw['message']) && is_string($raw['message'])) {
                    $result['message'] = $raw['message'];
                }
                // 插件额外信息统一放入 details，不污染顶层结构
                $result['details'] = isset($raw['details']) && is_array($raw['details'])
                    ? $raw['details']
                    : array_diff_key($raw, array_flip(['healthy', 'message']));
            }
        }

        return $result;
    }

    private function makeExecutableEntry(PluginManifest $manifest): object
    {
        $this->fileLoader->ensureLoaded($manifest);
        $entry = $this->container->make($manifest->entryClass);

        if (! method_exists($entry, 'execute')) {
            throw new BusinessException('插件入口类缺少 execute 方法', 42200);
        }

        return $entry;
    }

    private function makeAdapter(PluginManifest $manifest, string $contract): ?object
    {
        return match ($contract) {
            PaymentGatewayInterface::class => new PluginPaymentGateway($this, $manifest),
            VerificationDriver::class => new PluginVerificationDriver($this, $manifest),
            SmsDriver::class => new PluginSmsDriver($this, $manifest),
            MailDriver::class => new PluginMailDriver($this, $manifest),
            UpstreamDriver::class => new PluginUpstreamDriver($this, $manifest),
            default => null,
        };
    }

    private function resolveEntryKey(object $entry): ?string
    {
        if (! method_exists($entry, 'key')) {
            return null;
        }

        $key = trim((string) $entry->key());

        return $key !== '' ? $key : null;
    }
}
