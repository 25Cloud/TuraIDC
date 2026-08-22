<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PluginScanner
{
    private string $pluginsBasePath;

    /**
     * 清单缓存，键为 `domain:slug`。find() 会被运行时、绑定解析、列表接口和框架 boot
     * 反复调用，没有这层缓存时每次都要 realpath + require config.php。
     *
     * 只缓存解析成功的清单，并附带来源文件的 mtime：队列 worker 是长驻进程，
     * 容器单例跨任务存活，靠 mtime 校验才能在插件文件更新后自动失效。
     *
     * @var array<string, array{manifest: PluginManifest, path: string, mtime: int}>
     */
    private array $manifestCache = [];

    public function __construct(
        private readonly Filesystem $files,
    ) {
        $this->pluginsBasePath = base_path('plugins');
    }

    /**
     * @return array<int, PluginManifest>
     */
    public function scan(?string $domain = null): array
    {
        $domains = $domain !== null
            ? [PluginDomain::assertValid($domain)]
            : PluginDomain::values();

        $manifests = [];

        foreach ($domains as $currentDomain) {
            $domainDirectory = $this->domainDirectory($currentDomain);
            if (! $this->files->isDirectory($domainDirectory)) {
                continue;
            }

            foreach ($this->files->directories($domainDirectory) as $pluginDirectory) {
                $manifest = $this->readManifest($currentDomain, $pluginDirectory);
                if ($manifest instanceof PluginManifest) {
                    $this->rememberManifest($manifest);
                    $manifests[] = $manifest;
                }
            }
        }

        usort($manifests, static function (PluginManifest $left, PluginManifest $right): int {
            return [$left->domain, $left->name, $left->slug] <=> [$right->domain, $right->name, $right->slug];
        });

        return $manifests;
    }

    public function find(string $domain, string $slug): ?PluginManifest
    {
        $resolvedDomain = PluginDomain::assertValid($domain);
        $resolvedSlug = trim($slug);
        if ($resolvedSlug === '') {
            return null;
        }

        $cached = $this->cachedManifest($resolvedDomain.':'.$resolvedSlug);
        if ($cached instanceof PluginManifest) {
            return $cached;
        }

        $directory = $this->domainDirectory($resolvedDomain).DIRECTORY_SEPARATOR.$resolvedSlug;
        $manifest = $this->readManifest($resolvedDomain, $directory, false);
        $this->rememberManifest($manifest);

        return $manifest;
    }

    private function cachedManifest(string $cacheKey): ?PluginManifest
    {
        $entry = $this->manifestCache[$cacheKey] ?? null;
        if ($entry === null) {
            return null;
        }

        clearstatcache(true, $entry['path']);
        if (@filemtime($entry['path']) !== $entry['mtime']) {
            unset($this->manifestCache[$cacheKey]);

            return null;
        }

        return $entry['manifest'];
    }

    private function rememberManifest(?PluginManifest $manifest): void
    {
        if (! $manifest instanceof PluginManifest) {
            return;
        }

        $path = $this->manifestSourcePath($manifest);
        $mtime = $path === null ? false : @filemtime($path);
        if ($path === null || $mtime === false) {
            return;
        }

        $this->manifestCache[$manifest->domain.':'.$manifest->slug] = [
            'manifest' => $manifest,
            'path' => $path,
            'mtime' => $mtime,
        ];
    }

    private function manifestSourcePath(PluginManifest $manifest): ?string
    {
        foreach ([$manifest->configPath(), $manifest->basePath.DIRECTORY_SEPARATOR.'plugin.json'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function requireManifest(string $domain, string $slug): PluginManifest
    {
        $manifest = $this->find($domain, $slug);

        if (! $manifest instanceof PluginManifest) {
            throw new BusinessException('插件目录不存在或清单无效', 42200);
        }

        $this->assertManifestHash($domain, $slug, $manifest);

        return $manifest;
    }

    /**
     * 计算插件清单文件（config.php 或 plugin.json）的内容哈希，供安装时记录。
     */
    public function manifestContentHash(string $domain, string $slug): ?string
    {
        $manifest = $this->find($domain, $slug);

        return $manifest instanceof PluginManifest ? $this->configHashFor($manifest) : null;
    }

    /**
     * 轻量篡改检测：已安装插件清单内容哈希与安装时记录不一致时记 warning（不阻断运行）。
     */
    private function assertManifestHash(string $domain, string $slug, PluginManifest $manifest): void
    {
        try {
            if (! Schema::hasTable('integration_plugins') || ! Schema::hasColumn('integration_plugins', 'manifest_hash')) {
                return;
            }

            $recorded = DB::table('integration_plugins')
                ->where('domain', $domain)
                ->where('slug', $slug)
                ->value('manifest_hash');
            if ($recorded === null) {
                return;
            }

            $current = $this->configHashFor($manifest);
            if ($current !== null && hash_equals((string) $recorded, $current) === false) {
                Log::warning('[plugins] 插件清单被篡改：清单文件内容与安装时记录不一致', [
                    'domain' => $domain,
                    'slug' => $slug,
                    'recorded_hash' => $recorded,
                    'current_hash' => $current,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::debug('[plugins] 插件清单哈希比对失败，已跳过', [
                'domain' => $domain,
                'slug' => $slug,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function configHashFor(PluginManifest $manifest): ?string
    {
        $configPath = $manifest->configPath();
        if ($this->files->exists($configPath)) {
            return hash('sha256', (string) $this->files->get($configPath));
        }

        $jsonPath = $manifest->basePath.DIRECTORY_SEPARATOR.'plugin.json';
        if ($this->files->exists($jsonPath)) {
            return hash('sha256', (string) $this->files->get($jsonPath));
        }

        return null;
    }

    public function domainDirectory(string $domain): string
    {
        return $this->pluginsBasePath.DIRECTORY_SEPARATOR.PluginDomain::directoryName($domain);
    }

    private function readManifest(string $domain, string $pluginDirectory, bool $ignoreInvalid = true): ?PluginManifest
    {
        $normalizedDirectory = $this->normalizePluginDirectory($domain, $pluginDirectory);
        if ($normalizedDirectory === null) {
            return null;
        }

        $configPath = $normalizedDirectory.DIRECTORY_SEPARATOR.'config.php';
        $pluginJsonPath = $normalizedDirectory.DIRECTORY_SEPARATOR.'plugin.json';

        if (! $this->files->exists($configPath) && ! $this->files->exists($pluginJsonPath)) {
            return null;
        }

        try {
            $payload = $this->files->exists($configPath)
                ? require $configPath
                : json_decode((string) $this->files->get($pluginJsonPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            if ($ignoreInvalid) {
                return null;
            }

            throw new BusinessException('插件清单 JSON 解析失败', 42200);
        }

        try {
            return $this->buildManifest($domain, basename($normalizedDirectory), $normalizedDirectory, is_array($payload) ? $payload : []);
        } catch (\Throwable) {
            if ($ignoreInvalid) {
                return null;
            }

            throw new BusinessException('插件清单内容无效', 42200);
        }
    }

    private function normalizePluginDirectory(string $domain, string $pluginDirectory): ?string
    {
        $baseDirectory = $this->domainDirectory($domain);
        $baseRealPath = realpath($baseDirectory);
        $pluginRealPath = realpath($pluginDirectory);

        if ($baseRealPath === false || $pluginRealPath === false) {
            return null;
        }

        $prefix = rtrim($baseRealPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($pluginRealPath.DIRECTORY_SEPARATOR, $prefix)) {
            return null;
        }

        return $pluginRealPath;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildManifest(string $domain, string $slug, string $basePath, array $payload): PluginManifest
    {
        $payload = $this->normalizeConfigPayload($payload);
        $manifestDomain = trim((string) ($payload['domain'] ?? ''));
        $manifestSlug = trim((string) ($payload['slug'] ?? $slug));
        $key = trim((string) ($payload['key'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $version = trim((string) ($payload['version'] ?? '1.0.0'));
        $entryClass = trim((string) ($payload['entry'] ?? ''));
        $providerClass = trim((string) ($payload['provider'] ?? ''));

        if ($manifestDomain !== $domain || $manifestSlug !== $slug) {
            throw new BusinessException('插件目录与清单声明不一致', 42200);
        }

        if ($key === '' || $name === '' || $entryClass === '') {
            throw new BusinessException('插件清单缺少必要字段', 42200);
        }

        $capabilities = array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            (array) ($payload['capabilities'] ?? [])
        )));

        $configSchema = array_values(array_filter(
            (array) ($payload['config_schema'] ?? []),
            static fn (mixed $item): bool => is_array($item) && trim((string) ($item['key'] ?? '')) !== ''
        ));

        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];

        return new PluginManifest(
            domain: $domain,
            slug: $slug,
            key: $key,
            name: $name,
            version: $version,
            entryClass: $entryClass,
            providerClass: $providerClass !== '' ? $providerClass : null,
            capabilities: $capabilities,
            configSchema: $configSchema,
            basePath: $basePath,
            extra: $extra,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeConfigPayload(array $payload): array
    {
        $info = is_array($payload['info'] ?? null) ? $payload['info'] : [];
        if ($info !== []) {
            $payload = array_merge($info, array_diff_key($payload, ['info' => true]));
        }

        if (! isset($payload['config_schema']) && is_array($payload['config'] ?? null)) {
            $payload['config_schema'] = $this->configDefinitionsToSchema($payload['config']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function configDefinitionsToSchema(array $definitions): array
    {
        $schema = [];
        $extraKeys = [
            'placeholder',
            'description',
            'content',
            'theme',
            'width',
            'group',
            'disabled',
            'visible',
            'min',
            'max',
            'step',
            'rows',
            'rules',
            'visible_when',
            // divider 用：声明该分组可折叠，以及初始是否收起（默认收起）。
            // 高级项大多有合理默认值，收起来能避免管理员误改而配坏。
            'collapsible',
            'collapsed',
        ];

        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || trim($key) === '' || ! is_array($definition)) {
                continue;
            }

            $item = [
                'key' => trim($key),
                'label' => (string) ($definition['label'] ?? $definition['title'] ?? $key),
                'type' => (string) ($definition['type'] ?? 'text'),
                'required' => (bool) ($definition['required'] ?? false),
                'secret' => (bool) ($definition['secret'] ?? false),
                'options' => $definition['options'] ?? null,
                'default' => $definition['default'] ?? $definition['value'] ?? null,
            ];

            foreach ($extraKeys as $extraKey) {
                if (array_key_exists($extraKey, $definition)) {
                    $item[$extraKey] = $definition[$extraKey];
                }
            }

            $schema[] = $item;
        }

        return $schema;
    }
}
