<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\Service;
use App\Services\Integrations\Plugins\PluginBindingResolver;

/**
 * 服务属性解析共享服务（无外部依赖）
 * 负责：resolveServiceRootGroup、resolveGroupedOverviewTypeValue、resolveConsoleMode
 * 被 ServiceOverviewService 和 ServiceTransformService 共同依赖，以打破循环依赖。
 */
class ServiceResolverService
{
    private const CONSOLE_MODE_DEFAULT = 'default';

    private const CONSOLE_MODE_NAT = 'nat';

    private const NAT_CONSOLE_TYPE_VALUES = [
        'nat',
        'cloud_desktop',
        'cloud_pc',
        'cloudpc',
        'nat_console',
    ];

    private const NAT_CONSOLE_TEXT_KEYWORDS = [
        '云电脑',
        '云桌面',
        '桌面云',
        'cloud pc',
        'cloud desktop',
    ];

    private ?PluginBindingResolver $bindingResolver = null;

    public function resolveServiceRootGroup(Service $service): ?FirstProductGroup
    {
        $service->loadMissing('product.productGroup.secondProductGroup.firstProductGroup');

        return $service->product?->productGroup?->secondProductGroup?->firstProductGroup;
    }

    public function resolveGroupedOverviewTypeValue(Service $service): string
    {
        $service->loadMissing('product.productGroup.secondProductGroup.firstProductGroup');

        $productType = trim((string) ($service->product?->product_type ?? ''));
        if ($productType !== '') {
            return ProductType::normalizeBusinessValue($productType);
        }

        $serviceTypeCode = trim((string) ($service->product?->service_type_code ?? ''));
        if ($serviceTypeCode !== '') {
            return ProductType::normalizeBusinessValue($serviceTypeCode);
        }

        $firstGroup = $service->product?->productGroup?->secondProductGroup?->firstProductGroup;
        if ($firstGroup instanceof FirstProductGroup) {
            return ProductType::businessValueForFirstGroup($firstGroup);
        }

        return ProductType::OTHER;
    }

    public function resolveConsoleMode(Service $service, array $provisionData = []): string
    {
        $provisionData = $provisionData !== [] ? $provisionData : $this->serviceProvisionData($service);
        $explicitMode = strtolower(trim((string) ($provisionData['console_mode'] ?? '')));

        if (in_array($explicitMode, [self::CONSOLE_MODE_DEFAULT, self::CONSOLE_MODE_NAT], true)) {
            return $explicitMode;
        }

        return $this->isNatConsoleService($service, $provisionData)
            ? self::CONSOLE_MODE_NAT
            : self::CONSOLE_MODE_DEFAULT;
    }

    private function isNatConsoleService(Service $service, array $provisionData = []): bool
    {
        $provisionData = $provisionData !== [] ? $provisionData : $this->serviceProvisionData($service);
        $catalogProductType = $this->normalizeConsoleTypeValue($this->resolveGroupedOverviewTypeValue($service));

        if (in_array($catalogProductType, self::NAT_CONSOLE_TYPE_VALUES, true)) {
            return true;
        }

        $firstGroup = $this->resolveServiceRootGroup($service);
        $thirdGroup = $service->product?->productGroup;
        $secondGroup = $thirdGroup?->secondProductGroup;

        foreach ([
            (string) ($service->product?->name ?? ''),
            (string) ($service->product?->product_type ?? ''),
            (string) ($service->product?->service_type_code ?? ''),
            (string) ($firstGroup?->name ?? ''),
            (string) ($firstGroup?->description ?? ''),
            (string) ($secondGroup?->name ?? ''),
            (string) ($secondGroup?->description ?? ''),
            (string) ($thirdGroup?->name ?? ''),
            (string) ($thirdGroup?->description ?? ''),
            (string) ($firstGroup?->code ?? ''),
            (string) ($provisionData['upstream_product_name'] ?? ''),
        ] as $candidateText) {
            if ($this->containsNatConsoleKeyword($candidateText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(Service $service): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->bindingResolver()->serviceProvisionProjection($service);

        return $projection === [] ? $legacy : array_replace($legacy, $projection);
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function normalizeConsoleTypeValue(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return preg_replace('/_+/u', '_', $normalized) ?? $normalized;
    }

    private function containsNatConsoleKeyword(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        foreach (self::NAT_CONSOLE_TEXT_KEYWORDS as $keyword) {
            if (mb_stripos($normalized, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }
}
