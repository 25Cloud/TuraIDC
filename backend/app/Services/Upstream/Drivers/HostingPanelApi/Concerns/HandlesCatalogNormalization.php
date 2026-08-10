<?php

declare(strict_types=1);

namespace App\Services\Upstream\Drivers\HostingPanelApi\Concerns;

use App\Models\Supplier;

trait HandlesCatalogNormalization
{
    public function getProductCatalog(Supplier $supplier): array
    {
        $jwt = $this->login($supplier);
        $response = $this->get($supplier, '/v1/products', $jwt);

        return $this->normalizeProductCatalog($response);
    }

    private function normalizeProductCatalog(array $response): array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $firstGroups = is_array($payload['first_group'] ?? null) ? $payload['first_group'] : [];
        $groups = [];
        $flatProducts = [];

        foreach ($firstGroups as $firstGroup) {
            $firstGroupName = trim((string) ($firstGroup['name'] ?? ''));
            $productGroups = is_array($firstGroup['group'] ?? null) ? $firstGroup['group'] : [];

            foreach ($productGroups as $group) {
                $groupName = trim((string) ($group['name'] ?? ''));
                $groupLabel = $groupName !== ''
                    ? ($firstGroupName !== '' && $firstGroupName !== $groupName ? "{$firstGroupName} / {$groupName}" : $groupName)
                    : ($firstGroupName !== '' ? $firstGroupName : '未分组');

                $items = [];
                $catalogProducts = is_array($group['products'] ?? null)
                    ? $group['products']
                    : (is_array($group['product'] ?? null) ? $group['product'] : []);

                foreach ($catalogProducts as $product) {
                    $productId = (int) ($product['id'] ?? 0);
                    if ($productId <= 0) {
                        continue;
                    }

                    $item = [
                        'id' => $productId,
                        'name' => trim((string) ($product['name'] ?? '未命名商品')),
                        'type' => trim((string) ($product['type'] ?? '')),
                        'type_label' => $this->resolveCatalogProductTypeLabel((string) ($product['type'] ?? '')),
                        'description' => trim((string) ($product['description'] ?? '')),
                        'billingcycle' => trim((string) ($product['billingcycle'] ?? '')),
                        'product_price' => $this->normalizeCatalogAmount($product['product_price'] ?? null),
                        'monthly_price' => $this->resolveCatalogMonthlyPrice($product),
                        'setup_fee' => $this->normalizeCatalogAmount($product['setup_fee'] ?? null),
                        'allow_qty' => (int) ($product['allow_qty'] ?? 0),
                        'stock_control' => (int) ($product['stock_control'] ?? 0),
                        'qty' => is_numeric($product['qty'] ?? null) ? max((int) $product['qty'], 0) : null,
                        'stock' => $this->normalizeCatalogStock($product),
                        'first_group_name' => $firstGroupName,
                        'group_name' => $groupName,
                        'group_label' => $groupLabel,
                    ];

                    $items[] = $item;
                    $flatProducts[] = $item;
                }

                if ($items !== []) {
                    $groups[] = [
                        'key' => 'group-'.md5($groupLabel),
                        'label' => $groupLabel,
                        'items' => $items,
                    ];
                }
            }
        }

        return [
            'groups' => $groups,
            'products' => $flatProducts,
        ];
    }

    private function resolveCatalogProductTypeLabel(string $type): string
    {
        return match (trim($type)) {
            'cloud', 'vps' => '云服务器',
            'server', 'dedicated' => '独立服务器',
            'hosting', 'virtualhosting' => '虚拟主机',
            'domain' => '域名',
            default => trim($type) !== '' ? trim($type) : '未分类',
        };
    }

    private function resolveCatalogMonthlyPrice(array $product): ?string
    {
        $billingCycle = strtolower(trim((string) ($product['billingcycle'] ?? '')));
        if ($billingCycle !== '' && $billingCycle !== 'monthly') {
            return null;
        }

        return $this->normalizeCatalogAmount($product['product_price'] ?? null);
    }

    private function normalizeCatalogStock(array $product): int
    {
        if ((int) ($product['stock_control'] ?? 0) !== 1) {
            return -1;
        }

        $qty = $product['qty'] ?? null;
        if ($qty === null || $qty === '' || ! is_numeric($qty)) {
            return 0;
        }

        return max((int) $qty, 0);
    }

    private function normalizeCatalogAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
