<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Support\ProductGroupHierarchyFields;

class ProductFullPathResolver
{
    public function __construct(
        private readonly ?ProductDisplayNameResolver $displayNameResolver = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotForProduct(Product $product, string $productName, array $configSnapshot = []): array
    {
        $productName = trim($productName) ?: $this->nameForProduct($product, $configSnapshot);
        $segments = $this->segmentsForProduct($product, $productName);
        $fullPath = $segments !== [] ? implode('/', $segments) : $productName;

        return array_filter([
            'product_full_path' => $fullPath,
            'product_path_segments' => $segments,
            'first_product_group_name' => $segments[0] ?? null,
            'second_product_group_name' => $segments[1] ?? null,
            'third_product_group_name' => $segments[2] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function nameForOrder(Order $order): string
    {
        $snapshotName = $this->nameFromSnapshot((array) ($order->config_snapshot ?? []));
        if ($snapshotName !== '') {
            return $snapshotName;
        }

        $name = trim((string) ($order->display_product_name ?? $order->product_spec_snapshot ?? ''));
        if ($name !== '' && $name !== '未配置规格') {
            return $name;
        }

        return $order->product instanceof Product
            ? $this->nameForProduct($order->product, (array) ($order->config_snapshot ?? []))
            : '';
    }

    public function pathForOrder(Order $order): string
    {
        $snapshotPath = $this->pathFromSnapshot((array) ($order->config_snapshot ?? []));
        if ($snapshotPath !== '') {
            return $snapshotPath;
        }

        $productName = $this->nameForOrder($order);
        if ($order->product instanceof Product) {
            $segments = $this->segmentsForProduct(
                $order->product,
                $productName,
                trim((string) ($order->product_type_snapshot ?? '')) ?: null,
            );

            if ($segments !== []) {
                return implode('/', $segments);
            }
        }

        return $productName;
    }

    public function pathForInvoice(Invoice $invoice): string
    {
        $snapshotPath = $this->pathFromSnapshot((array) ($invoice->config_snapshot ?? []));
        if ($snapshotPath !== '') {
            return $snapshotPath;
        }

        if ($invoice->order instanceof Order) {
            $orderPath = $this->pathForOrder($invoice->order);
            if ($orderPath !== '') {
                return $orderPath;
            }
        }

        $productName = trim((string) ($invoice->display_product_name ?? $invoice->product_spec_snapshot ?? ''));
        if ($invoice->product instanceof Product) {
            $segments = $this->segmentsForProduct(
                $invoice->product,
                $productName,
                trim((string) ($invoice->product_type_snapshot ?? '')) ?: null,
            );

            if ($segments !== []) {
                return implode('/', $segments);
            }
        }

        return $productName;
    }

    public function pathFromSnapshot(array $snapshot): string
    {
        foreach (['product_full_path', 'product_path', 'product_display_path'] as $key) {
            $value = trim((string) ($snapshot[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $segments = $this->cleanSegments((array) ($snapshot['product_path_segments'] ?? []));

        return $segments !== [] ? implode('/', $segments) : '';
    }

    private function nameForProduct(Product $product, array $configSnapshot = []): string
    {
        $resolved = $this->resolveDisplayNameResolver()->resolveForProduct($product, $configSnapshot);
        foreach (['combined_display_name', 'product_spec_display', 'product_display_name'] as $key) {
            $value = trim((string) ($resolved[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return (int) ($product->id ?? 0) > 0 ? '产品 #'.(int) $product->id : '';
    }

    /**
     * @return list<string>
     */
    private function segmentsForProduct(Product $product, string $productName, ?string $productTypeOverride = null): array
    {
        if ($product->exists) {
            $product->loadMissing([
                'productGroup.secondProductGroup.firstProductGroup',
            ]);
        }

        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $productType = ProductType::businessValueForFirstGroup(
            $product->resolvedProductGroupHierarchy()[0],
            $productTypeOverride ?? $hierarchy['product_type'] ?? $product->product_type ?? $product->service_type_code
        );
        $firstName = trim((string) ($hierarchy['first_product_group_name'] ?? '')) ?: ProductType::businessLabelOf($productType);

        return $this->cleanSegments([
            $firstName,
            trim((string) ($hierarchy['second_product_group_name'] ?? '')),
            trim((string) ($hierarchy['third_product_group_name'] ?? '')),
            trim($productName),
        ]);
    }

    /**
     * @param  list<mixed>  $segments
     * @return list<string>
     */
    private function cleanSegments(array $segments): array
    {
        $clean = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || $segment === '-' || in_array($segment, $clean, true)) {
                continue;
            }

            $clean[] = $segment;
        }

        return $clean;
    }

    private function nameFromSnapshot(array $snapshot): string
    {
        foreach (['product_name', 'product_spec_snapshot'] as $key) {
            $value = trim((string) ($snapshot[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveDisplayNameResolver(): ProductDisplayNameResolver
    {
        return $this->displayNameResolver ?? new ProductDisplayNameResolver;
    }
}
