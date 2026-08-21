<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCloudConfigTemplate;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceAdapter;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceDriver;

class ProviderResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    public function test_it_resolves_zjmf_supplier_key_to_zjmf_driver(): void
    {
        $resolver = $this->makeResolver();
        $supplier = new Supplier;

        $resolved = $resolver->resolveForSupplier($supplier);

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $resolved->rawKey());
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $resolved->key());
        $this->assertSame('ZJMF 财务接口', $resolved->label());
        $this->assertContains(ProvidesProvisioning::class, $resolved->capabilities());
        $this->assertContains(ProvidesConsoleCatalog::class, $resolved->capabilities());
        $this->assertTrue($resolved->supports(ProvidesProvisioning::class));
        $descriptorPayload = $resolved->descriptor()->toArray();
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $descriptorPayload['key']);
        $this->assertSame('ZJMF 财务接口', $descriptorPayload['label']);
        $this->assertSame($resolved->capabilities(), $descriptorPayload['capabilities']);
        $this->assertArrayHasKey('supplier_form', $descriptorPayload);
    }

    public function test_zjmf_capabilities_resolve_to_zjmf_adapter_instead_of_shared_transport(): void
    {
        $resolver = $this->makeResolver();
        $supplier = new Supplier;

        $catalogCapability = $resolver
            ->resolveForSupplier($supplier)
            ->require(ProvidesConsoleCatalog::class);

        $this->assertInstanceOf(ZjmfFinanceAdapter::class, $catalogCapability);
        $this->assertNotInstanceOf(HostingPanelApiTransport::class, $catalogCapability);
    }

    public function test_service_level_provider_key_takes_priority_over_product_and_supplier(): void
    {
        $resolver = $this->makeResolver(new class extends PluginBindingResolver
        {
            public function providerKeyForService(Service $service): ?string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }
        });

        $service = new Service;

        $resolved = $resolver->resolveForService($service);

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $resolved->rawKey());
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $resolved->key());
        $this->assertTrue($resolved->isResolved());
    }

    public function test_service_binding_ignores_stale_legacy_provider_payload(): void
    {
        $resolver = $this->makeResolver(new class extends PluginBindingResolver
        {
            public function providerKeyForService(Service $service): ?string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }
        });

        $service = new Service([
            'provision_data' => [
                'provider' => ProviderKey::HOSTING_PANEL_API,
                'supplier_id' => 1,
            ],
        ]);

        $resolved = $resolver->resolveForService($service);

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $resolved->key());
        $this->assertTrue($resolved->isResolved());
    }

    private function makeResolver(?PluginBindingResolver $bindingResolver = null): ProviderResolver
    {
        $transport = $this->createMock(HostingPanelApiTransport::class);

        return new ProviderResolver(new ProviderRegistry([
            new HostingPanelApiDriver($transport),
            new ZjmfFinanceDriver(new ZjmfFinanceAdapter($transport, new ZjmfCloudConfigTemplate)),
        ]), $bindingResolver ?? new class extends PluginBindingResolver
        {
            public function providerKeyForSupplier(Supplier $supplier): ?string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }

            public function providerKeyForProduct(Product $product): ?string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }

            public function providerKeyForService(Service $service): ?string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }
        });
    }
}
