<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

final class ZjmfProductTypeMapper
{
    private const CLOUD_TYPES = [
        'dcimcloud' => '云服务器',
        'cloud' => '云服务器',
        'vps' => '云服务器',
    ];

    public function supportsCloudTemplate(array $product): bool
    {
        return array_key_exists($this->normalizeType((string) ($product['type'] ?? '')), self::CLOUD_TYPES);
    }

    public function label(string $type): string
    {
        $normalizedType = $this->normalizeType($type);

        return self::CLOUD_TYPES[$normalizedType] ?? ($normalizedType !== '' ? $normalizedType : '未分类');
    }

    /**
     * @param  array<string,mixed>  $product
     * @return array<string,mixed>
     */
    public function normalizeProduct(array $product): array
    {
        $product['type_label'] = $this->label((string) ($product['type'] ?? ''));

        return $product;
    }

    private function normalizeType(string $type): string
    {
        return strtolower(trim($type));
    }
}
