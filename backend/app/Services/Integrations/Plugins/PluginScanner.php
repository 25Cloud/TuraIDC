<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use Illuminate\Filesystem\Filesystem;

class PluginScanner
{
    private string $pluginsBasePath;

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

        $directory = $this->domainDirectory($resolvedDomain).DIRECTORY_SEPARATOR.$resolvedSlug;

        return $this->readManifest($resolvedDomain, $directory, false);
    }

    public function requireManifest(string $domain, string $slug): PluginManifest
    {
        $manifest = $this->find($domain, $slug);

        if (! $manifest instanceof PluginManifest) {
            throw new BusinessException('插件目录不存在或清单无效', 42200);
        }

        return $manifest;
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
