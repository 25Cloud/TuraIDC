<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Service;
use App\Services\ProductCatalog\ProductDisplayNameResolver;

class ServiceHostname
{
    public static function resolveInstanceNameFromProduct(
        ?Product $product,
        array $configSnapshot = [],
        string $fallback = '',
        string $preferredSpecText = ''
    ): string {
        if (! $product instanceof Product) {
            return trim($fallback);
        }

        $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($product, $configSnapshot);
        $instanceSpecText = trim((string) ($displayPayload['instance_spec_text'] ?? ''));
        $productSpecDisplay = trim((string) ($displayPayload['product_spec_display'] ?? ''));
        $combinedDisplayName = trim((string) ($displayPayload['combined_display_name'] ?? ''));

        $specText = trim($preferredSpecText);
        if ($specText === '') {
            $specText = $instanceSpecText;
        }
        if ($specText === '' && $productSpecDisplay !== '') {
            $specText = $productSpecDisplay;
        }
        if ($specText !== '') {
            return $specText;
        }

        return $combinedDisplayName !== '' ? $combinedDisplayName : trim($fallback);
    }

    public static function resolveInstanceName(?Service $service, array $provisionData = [], array $host = []): string
    {
        $customServiceName = trim((string) ($provisionData['custom_service_name'] ?? ''));
        if ($customServiceName !== '') {
            return $customServiceName;
        }

        $defaultServiceName = trim((string) ($provisionData['default_service_name'] ?? ''));
        if ($defaultServiceName !== '') {
            return $defaultServiceName;
        }

        return trim((string) ($service?->name ?? ''));
    }

    public static function custom(array $provisionData = []): string
    {
        return trim((string) ($provisionData['custom_hostname'] ?? ''));
    }

    public static function hasCustom(array $provisionData = []): bool
    {
        return self::custom($provisionData) !== '';
    }

    public static function resolveDisplayDomain(?Service $service, array $provisionData = [], array $host = []): string
    {
        $customHostname = self::custom($provisionData);
        if ($customHostname !== '') {
            return $customHostname;
        }

        $remoteHostname = trim((string) ($host['domain'] ?? ''));
        if ($remoteHostname !== '') {
            return $remoteHostname;
        }

        $serviceDomain = trim((string) ($service?->domain ?? ''));
        if ($serviceDomain !== '') {
            return $serviceDomain;
        }

        return trim((string) ($provisionData['requested_host'] ?? ''));
    }

    public static function resolveConnectionHostname(
        ?Service $service,
        array $provisionData = [],
        array $cachedConnection = [],
        array $host = []
    ): string {
        $displayDomain = self::resolveDisplayDomain($service, $provisionData, $host);
        if ($displayDomain !== '') {
            return $displayDomain;
        }

        return trim((string) ($cachedConnection['hostname'] ?? ''));
    }

    public static function writeCustomHostname(array $provisionData, string $hostname, array $context = []): array
    {
        $normalized = trim($hostname);

        if ($normalized === '') {
            unset(
                $provisionData['custom_hostname'],
                $provisionData['custom_hostname_updated_at'],
                $provisionData['custom_hostname_updated_by_admin_id'],
                $provisionData['custom_hostname_updated_by_admin_name']
            );

            return $provisionData;
        }

        $provisionData['custom_hostname'] = $normalized;
        $provisionData['custom_hostname_updated_at'] = now()->format('Y-m-d H:i:s');

        $adminId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $adminName = trim((string) ($context['operator_name'] ?? ''));

        if ($adminId > 0) {
            $provisionData['custom_hostname_updated_by_admin_id'] = $adminId;
        }

        if ($adminName !== '') {
            $provisionData['custom_hostname_updated_by_admin_name'] = $adminName;
        }

        return $provisionData;
    }

    public static function writeCustomServiceName(array $provisionData, string $serviceName, array $context = []): array
    {
        $normalized = trim($serviceName);

        if ($normalized === '') {
            unset(
                $provisionData['custom_service_name'],
                $provisionData['custom_service_name_updated_at'],
                $provisionData['custom_service_name_updated_by_admin_id'],
                $provisionData['custom_service_name_updated_by_admin_name']
            );

            return $provisionData;
        }

        $provisionData['custom_service_name'] = $normalized;
        $provisionData['custom_service_name_updated_at'] = now()->format('Y-m-d H:i:s');

        $adminId = (int) (($context['operator_id'] ?? 0) ?: 0);
        $adminName = trim((string) ($context['operator_name'] ?? ''));

        if ($adminId > 0) {
            $provisionData['custom_service_name_updated_by_admin_id'] = $adminId;
        }

        if ($adminName !== '') {
            $provisionData['custom_service_name_updated_by_admin_name'] = $adminName;
        }

        return $provisionData;
    }

    public static function rememberDefaultServiceName(array $provisionData, string $serviceName): array
    {
        $normalized = trim($serviceName);

        if ($normalized === '') {
            return $provisionData;
        }

        if (trim((string) ($provisionData['default_service_name'] ?? '')) === '') {
            $provisionData['default_service_name'] = $normalized;
        }

        return $provisionData;
    }
}
