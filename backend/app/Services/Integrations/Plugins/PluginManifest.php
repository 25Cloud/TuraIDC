<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

final readonly class PluginManifest
{
    /**
     * @param  array<int, string>  $capabilities
     * @param  array<int, array<string, mixed>>  $configSchema
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $domain,
        public string $slug,
        public string $key,
        public string $name,
        public string $version,
        public string $entryClass,
        public ?string $providerClass,
        public array $capabilities,
        public array $configSchema,
        public string $basePath,
        public array $extra = [],
    ) {}

    public function configPath(): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.'config.php';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'slug' => $this->slug,
            'key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'entry_class' => $this->entryClass,
            'provider_class' => $this->providerClass,
            'capabilities' => $this->capabilities,
            'config_schema' => $this->configSchema,
            'base_path' => $this->basePath,
            'extra' => $this->extra,
        ];
    }
}
