<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceResolverService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceConsoleTemplateResolverTest extends TestCase
{
    #[Test]
    public function console_mode_resolves_from_product_type_and_keywords(): void
    {
        $resolver = new ServiceResolverService;

        // 控制台模式改由商品类型与关键词判定（console_template 列不再作为选择器）：
        // 普通云服务器即使残留旧 port_mapping 模板值也走 default
        $portMappingService = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_PORT_MAPPING, 'cloud_server');
        $this->assertSame('default', $resolver->resolveConsoleMode($portMappingService));

        // 云桌面类商品走 NAT 控制台
        $desktopService = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_COMPUTE, 'cloud_desktop');
        $this->assertSame('nat', $resolver->resolveConsoleMode($desktopService));
    }

    #[Test]
    public function empty_product_console_template_resolves_by_product_type(): void
    {
        $resolver = new ServiceResolverService;
        $legacyService = $this->serviceWithProduct(null, 'cloud_desktop');

        $this->assertSame('nat', $resolver->resolveConsoleMode($legacyService));
    }

    private function serviceWithProduct(?string $consoleTemplate, string $productType): Service
    {
        $product = new Product([
            'product_type' => $productType,
            'console_template' => $consoleTemplate,
        ]);
        $service = new Service(['provision_data' => []]);
        $service->setRelation('product', $product);

        return $service;
    }
}
