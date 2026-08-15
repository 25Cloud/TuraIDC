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
    public function product_console_template_is_the_only_console_page_selector(): void
    {
        $resolver = new ServiceResolverService;

        $portMappingService = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_PORT_MAPPING, 'cloud_server');
        $this->assertSame('nat', $resolver->resolveConsoleMode($portMappingService));

        $computeService = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_COMPUTE, 'cloud_desktop');
        $this->assertSame('default', $resolver->resolveConsoleMode($computeService));
    }

    #[Test]
    public function empty_product_console_template_defaults_to_the_compute_console(): void
    {
        $resolver = new ServiceResolverService;
        $legacyService = $this->serviceWithProduct(null, 'cloud_desktop');

        $this->assertSame('default', $resolver->resolveConsoleMode($legacyService));
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
