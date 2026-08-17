<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Services\Upstream\Support\CloudConfigTemplate;

final class ZjmfCloudConfigTemplate extends CloudConfigTemplate
{
    public function __construct(
        private readonly ZjmfProductTypeMapper $productTypeMapper = new ZjmfProductTypeMapper,
    ) {}

    public function supports(array $product): bool
    {
        return $this->productTypeMapper->supportsCloudTemplate($product);
    }

    protected function source(): string
    {
        return 'zjmf_api';
    }
}
