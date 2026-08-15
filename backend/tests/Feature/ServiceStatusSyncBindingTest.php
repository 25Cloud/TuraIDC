<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStatusSyncBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    #[Test]
    public function it_syncs_only_services_with_normalized_upstream_bindings(): void
    {
        $statusSync = new class implements ProvidesStatusSync
        {
            public array $items = [];

            public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize): array
            {
                $this->items = array_values($items);

                $responses = [];
                foreach ($items as $item) {
                    $serviceId = (int) ($item['service_id'] ?? 0);
                    $hostId = (int) ($item['host_id'] ?? 0);
                    $responses[$serviceId] = [
                        'host' => [
                            'domainstatus' => 'Active',
                            'domain' => 'synced-'.$hostId.'.example.test',
                            'product_id' => 9001,
                            'product_name' => 'Remote Product',
                            'dedicatedip' => '192.0.2.'.(($hostId % 200) + 1),
                            'username' => 'root',
                            'password' => 'secret',
                            'port' => 22,
                            'os' => 'linux',
                        ],
                        'runtime' => [
                            'status' => 'running',
                            'des' => 'Running',
                        ],
                    ];
                }

                return ['services' => $responses];
            }
        };

        $legacyOnlyService = $this->makeServiceStack(
            suffix: 'legacy-only',
            upstreamProductId: 7001,
            upstreamHostId: 11111,
            createServiceBinding: false,
        )['service'];

        $boundService = $this->makeServiceStack(
            suffix: 'binding-only',
            upstreamProductId: 7002,
            upstreamHostId: 22222,
            createServiceBinding: true,
            provisionData: [
                'provider' => ProviderKey::HOSTING_PANEL_API,
                'source_type' => 'upstream',
            ],
        )['service'];

        $summary = $this->makeStatusSyncService($statusSync)->syncServices(new EloquentCollection([
            $legacyOnlyService->fresh(),
            $boundService->fresh(),
        ]));

        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['synced']);
        $this->assertSame([
            [
                'service_id' => (int) $boundService->id,
                'host_id' => 22222,
            ],
        ], $statusSync->items);

        $this->assertSame('synced-22222.example.test', (string) $boundService->refresh()->domain);
        $this->assertSame('legacy-only.example.test', (string) $legacyOnlyService->refresh()->domain);
        $this->assertDatabaseHas('service_runtime_snapshots', [
            'service_id' => (int) $boundService->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status_key' => 'running',
        ]);
    }

    #[Test]
    public function it_records_status_sync_failures_on_the_service_binding(): void
    {
        $statusSync = new class implements ProvidesStatusSync
        {
            public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize): array
            {
                throw new \RuntimeException('sync exploded');
            }
        };

        $boundService = $this->makeServiceStack(
            suffix: 'binding-failure',
            upstreamProductId: 7003,
            upstreamHostId: 33333,
            createServiceBinding: true,
            provisionData: [
                'provider' => ProviderKey::ZJMF_FINANCE_API,
                'source_type' => 'upstream',
            ],
        )['service'];

        $summary = $this->makeStatusSyncService($statusSync)->syncServices(new EloquentCollection([
            $boundService->fresh(),
        ]));

        $this->assertSame(1, $summary['scanned']);
        $this->assertSame(1, $summary['failed']);

        $this->assertSame('sync exploded', DB::table('service_upstream_bindings')
            ->where('service_id', (int) $boundService->id)
            ->value('last_sync_error'));
        $this->assertSame('sync exploded', (string) (($boundService->refresh()->provision_data)['status_sync_error'] ?? ''));
    }

    private function makeStatusSyncService(object $statusSync): ServiceStatusSyncService
    {
        $driver = new class($statusSync) implements UpstreamDriver
        {
            public function __construct(private readonly object $statusSync) {}

            public function key(): string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }

            public function label(): string
            {
                return 'Zjmf Finance';
            }

            public function capabilities(): array
            {
                return [ProvidesStatusSync::class];
            }

            public function supports(string $capability): bool
            {
                return $capability === ProvidesStatusSync::class;
            }

            public function resolve(string $capability): ?object
            {
                return $this->supports($capability) ? $this->statusSync : null;
            }
        };

        return new ServiceStatusSyncService(new ProviderResolver(new ProviderRegistry([$driver])));
    }

    /**
     * @param  array<string, mixed>|null  $provisionData
     * @return array{service: Service, product: Product, supplier: Supplier}
     */
    private function makeServiceStack(
        string $suffix,
        int $upstreamProductId,
        int $upstreamHostId,
        bool $createServiceBinding,
        ?array $provisionData = null,
    ): array {
        $unique = $suffix.'-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => $unique.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Status Supplier '.$unique,
            'code' => 'status-'.$unique,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$unique.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Status Product '.$unique,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => $upstreamProductId,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) $upstreamProductId,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Status Service '.$unique,
            'domain' => $suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => $provisionData ?? [
                'provider' => ProviderKey::ZJMF_FINANCE_API,
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => $upstreamProductId,
                'upstream_host_id' => $upstreamHostId,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        if ($createServiceBinding) {
            DB::table('service_upstream_bindings')->insert([
                'service_id' => (int) $service->id,
                'product_upstream_binding_id' => $productBindingId,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'upstream_service_id' => (string) $upstreamHostId,
                'status_snapshot' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'service' => $service,
            'product' => $product,
            'supplier' => $supplier,
        ];
    }
}
