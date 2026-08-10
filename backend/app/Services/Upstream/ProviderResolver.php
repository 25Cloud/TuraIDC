<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;

final class ProviderResolver
{
    private readonly PluginBindingResolver $bindingResolver;

    public function __construct(
        private readonly ProviderRegistry $registry,
        ?PluginBindingResolver $bindingResolver = null,
    ) {
        $this->bindingResolver = $bindingResolver ?? new PluginBindingResolver;
    }

    public function normalizeKey(string $raw): ?string
    {
        $normalized = trim($raw);
        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    public function resolveForSupplier(Supplier $supplier): ResolvedProvider
    {
        return $this->resolveByRawKey(
            $this->bindingResolver->providerKeyForSupplier($supplier)
        );
    }

    public function resolveForProduct(Product $product): ResolvedProvider
    {
        return $this->resolveByRawKey(
            $this->bindingResolver->providerKeyForProduct($product)
        );
    }

    public function resolveForService(Service $service): ResolvedProvider
    {
        return $this->resolveByRawKey(
            $this->bindingResolver->providerKeyForService($service)
        );
    }

    public function resolveByRawKey(?string $rawKey): ResolvedProvider
    {
        $rawKeyString = trim((string) $rawKey);
        $resolvedKey = $this->normalizeKey($rawKeyString);

        return new ResolvedProvider(
            $rawKeyString !== '' ? $rawKeyString : null,
            $resolvedKey,
            $this->registry->find($resolvedKey),
        );
    }
}
