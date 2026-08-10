<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Adapters;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;

final readonly class PluginUpstreamDriver implements ProvidesSupplierFormSchema, UpstreamDriver
{
    public function __construct(
        private PluginRuntimeRegistry $runtime,
        private PluginManifest $manifest,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function label(): string
    {
        return $this->manifest->name;
    }

    public function capabilities(): array
    {
        return $this->manifest->capabilities;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function resolve(string $capability): ?object
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'server.resolve_capability', [
            'capability' => $capability,
        ]);

        $resolved = $result['data']['resolved'] ?? null;

        return is_object($resolved) ? $resolved : null;
    }

    public function supplierFormSchema(): array
    {
        try {
            $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'server.supplier_form_schema');
        } catch (BusinessException) {
            return [];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $schema = is_array($data['supplier_form'] ?? null) ? $data['supplier_form'] : $data;

        return $schema;
    }
}
