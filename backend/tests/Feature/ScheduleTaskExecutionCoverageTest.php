<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Jobs\RunHeartbeatTaskJob;
use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Automation\AutoRenewService;
use App\Services\Automation\BillingAutomationService;
use App\Services\Automation\InvoiceCleanupAutomationService;
use App\Services\Automation\ScheduleTaskService;
use App\Services\Automation\ServiceLifecycleAutomationService;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\Finance\CouponCampaignService;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Referral\ReferralService;
use App\Services\Ticket\TicketAutomationService;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfInventoryAndServiceSyncTask;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfScheduledAuthRefreshTask;
use TuraIDC\Plugins\Servers\ZjmfFinance\ZjmfFinancePlugin;

class ScheduleTaskExecutionCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemoryDatabase();
        $this->createSettingsTable();
    }

    protected function tearDown(): void
    {
        foreach (['jobs', 'orders', 'services', 'supplier_plugin_bindings', 'integration_plugins', 'suppliers', 'settings'] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_schedule_task_overview_lists_registered_schedule_tasks(): void
    {
        $service = app(ScheduleTaskService::class);

        $tasks = collect($service->overview()['tasks'] ?? [])->values();

        $this->assertGreaterThanOrEqual(11, $tasks->count());

        foreach ([
            'refresh-hosting-panel-auth',
            'service-auto-renew',
            'referral-release-rewards',
            'service-lifecycle-maintenance',
            'service-status-sync',
            'billing-maintenance',
            'product-upstream-config-sync',
            'coupon-campaign-dispatch',
            'ticket-auto-close',
            'order-cleanup',
        ] as $expectedKey) {
            $this->assertTrue(
                $tasks->contains(fn (array $task): bool => ($task['key'] ?? null) === $expectedKey),
                'Missing schedule task key: '.$expectedKey
            );
        }

        $this->assertFalse(
            $tasks->contains(fn (array $task): bool => ($task['key'] ?? null) === 'vnc-ensure-relay'),
            'vnc-ensure-relay must not be registered as a heartbeat schedule task.'
        );
        $this->assertFalse(
            $tasks->contains(fn (array $task): bool => ($task['key'] ?? null) === 'sync-processing-order-status'),
            'Deprecated sync-processing-order-status must not be exposed as a current schedule task.'
        );

        $cleanupTask = $tasks->firstWhere('key', 'order-cleanup');
        $this->assertIsArray($cleanupTask);
        $this->assertSame('账单与充值清理', $cleanupTask['title'] ?? null);
        $this->assertTrue((bool) ($cleanupTask['manual_triggerable'] ?? false));
    }

    public function test_manual_dispatch_executes_each_triggerable_schedule_task(): void
    {
        $this->createSuppliersTable();
        $this->createSupplierPluginBindingTables();
        $scheduledAuthRefresh = new RecordingScheduledAuthRefresh;
        $providerRegistry = new ProviderRegistry([
            new FakeScheduledAuthRefreshDriver($scheduledAuthRefresh),
        ]);
        app()->instance(ProviderRegistry::class, $providerRegistry);
        app()->instance(ProviderResolver::class, new ProviderResolver($providerRegistry));

        $supplier = Supplier::query()->create([
            'name' => 'Mock Supplier',
            'interface_type' => ProviderKey::HOSTING_PANEL_API,
            'status' => 1,
        ]);
        $pluginId = DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => ProviderKey::HOSTING_PANEL_API,
            'plugin_key' => ProviderKey::HOSTING_PANEL_API,
            'name' => 'Hosting Panel API',
            'version' => '1.0.0',
            'entry_class' => FakeScheduledAuthRefreshDriver::class,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_plugin_bindings')->insert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::HOSTING_PANEL_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $autoRenewService = $this->createMock(AutoRenewService::class);
        $autoRenewService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['matched' => 0, 'paid' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0]);
        app()->instance(AutoRenewService::class, $autoRenewService);

        $referralService = $this->createMock(ReferralService::class);
        $referralService->expects($this->once())
            ->method('releaseMaturedRewards')
            ->with()
            ->willReturn(0);
        app()->instance(ReferralService::class, $referralService);

        $billingAutomationService = $this->createMock(BillingAutomationService::class);
        $billingAutomationService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['renew_notice_sent' => 0, 'renew_orders_created' => 0]);
        app()->instance(BillingAutomationService::class, $billingAutomationService);

        $couponCampaignService = $this->createMock(CouponCampaignService::class);
        $couponCampaignService->expects($this->once())
            ->method('dispatchDueCampaigns')
            ->with()
            ->willReturn(['matched' => 0, 'triggered' => 0, 'skipped' => 0, 'failed' => 0, 'coupon_ids' => []]);
        app()->instance(CouponCampaignService::class, $couponCampaignService);

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->expects($this->once())
            ->method('syncUpstreamProductConfigOptions')
            ->with()
            ->willReturn(['matched' => 0, 'synced' => 0, 'failed' => 0]);
        app()->instance(ProductCatalogService::class, $productCatalogService);

        $serviceLifecycleAutomationService = $this->createMock(ServiceLifecycleAutomationService::class);
        $serviceLifecycleAutomationService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['suspended' => 0, 'cancelled' => 0]);
        app()->instance(ServiceLifecycleAutomationService::class, $serviceLifecycleAutomationService);

        $serviceStatusSyncService = $this->createMock(ServiceStatusSyncService::class);
        $serviceStatusSyncService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['scanned' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0]);
        app()->instance(ServiceStatusSyncService::class, $serviceStatusSyncService);

        $ticketAutomationService = $this->createMock(TicketAutomationService::class);
        $ticketAutomationService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['closed' => 0]);
        app()->instance(TicketAutomationService::class, $ticketAutomationService);

        $invoiceCleanupAutomationService = $this->createMock(InvoiceCleanupAutomationService::class);
        $invoiceCleanupAutomationService->expects($this->once())
            ->method('handle')
            ->with()
            ->willReturn(['invoices_cancelled' => 0, 'orders_cancelled' => 0, 'recharges_expired' => 0]);
        app()->instance(InvoiceCleanupAutomationService::class, $invoiceCleanupAutomationService);

        foreach ([
            'refresh-hosting-panel-auth',
            'service-auto-renew',
            'referral-release-rewards',
            'billing-maintenance',
            'coupon-campaign-dispatch',
            'product-upstream-config-sync',
            'service-lifecycle-maintenance',
            'service-status-sync',
            'ticket-auto-close',
            'order-cleanup',
        ] as $taskKey) {
            RunHeartbeatTaskJob::dispatchSync($taskKey, null, null, 1, 'manual_trigger');
        }

        $this->assertSame([1], $scheduledAuthRefresh->supplierStatuses);
    }

    public function test_zjmf_auth_refresh_task_is_registered_from_enabled_plugin_hooks(): void
    {
        $this->createSuppliersTable();
        $this->createSupplierPluginBindingTables();

        $scheduledAuthRefresh = new RecordingScheduledAuthRefresh;
        $providerRegistry = new ProviderRegistry([
            new FakeScheduledAuthRefreshDriver($scheduledAuthRefresh, ProviderKey::ZJMF_FINANCE_API),
        ]);
        app()->instance(ProviderRegistry::class, $providerRegistry);
        app()->instance(ProviderResolver::class, new ProviderResolver($providerRegistry));

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Supplier',
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
        ]);
        $pluginId = DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => 'zjmf_finance',
            'plugin_key' => ProviderKey::ZJMF_FINANCE_API,
            'name' => 'ZJMF 财务接口',
            'version' => '1.0.0',
            'entry_class' => ZjmfFinancePlugin::class,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_plugin_bindings')->insert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tasks = collect(app(ScheduleTaskService::class)->overview()['tasks'] ?? []);
        $task = $tasks->firstWhere('key', ZjmfScheduledAuthRefreshTask::KEY);

        $this->assertIsArray($task);
        $this->assertSame('ZJMF 财务认证刷新', $task['title'] ?? null);
        $this->assertSame('third_party', $task['source_type'] ?? null);
        $this->assertSame('第三方任务', $task['source_label'] ?? null);

        RunHeartbeatTaskJob::dispatchSync(ZjmfScheduledAuthRefreshTask::KEY, null, null, 1, 'manual_trigger');

        $this->assertSame([1], $scheduledAuthRefresh->supplierStatuses);
    }

    public function test_zjmf_inventory_and_service_sync_task_runs_registered_plugin_hooks(): void
    {
        $this->createSuppliersTable();
        $this->createSupplierPluginBindingTables();

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Supplier',
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
        ]);
        $pluginId = DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => 'zjmf_finance',
            'plugin_key' => ProviderKey::ZJMF_FINANCE_API,
            'name' => 'ZJMF 财务接口',
            'version' => '1.0.0',
            'entry_class' => ZjmfFinancePlugin::class,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_plugin_bindings')->insert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productCatalogService = $this->createMock(ProductCatalogService::class);
        $productCatalogService->expects($this->once())
            ->method('syncUpstreamProductStocks')
            ->with(ProviderKey::ZJMF_FINANCE_API)
            ->willReturn([
                'matched_products' => 1,
                'matched_suppliers' => 1,
                'synced_products' => 1,
                'skipped_products' => 0,
                'failed_products' => 0,
            ]);
        app()->instance(ProductCatalogService::class, $productCatalogService);

        $serviceStatusSyncService = $this->createMock(ServiceStatusSyncService::class);
        $serviceStatusSyncService->expects($this->once())
            ->method('handleProvider')
            ->with(ProviderKey::ZJMF_FINANCE_API)
            ->willReturn([
                'scanned' => 1,
                'synced' => 1,
                'failed' => 0,
                'skipped' => 0,
            ]);
        app()->instance(ServiceStatusSyncService::class, $serviceStatusSyncService);

        $tasks = collect(app(ScheduleTaskService::class)->overview()['tasks'] ?? []);
        $task = $tasks->firstWhere('key', ZjmfInventoryAndServiceSyncTask::KEY);

        $this->assertIsArray($task);
        $this->assertSame('ZJMF 财务库存与服务同步', $task['title'] ?? null);
        $this->assertSame('third_party', $task['source_type'] ?? null);
        $this->assertSame('第三方任务', $task['source_label'] ?? null);

        RunHeartbeatTaskJob::dispatchSync(ZjmfInventoryAndServiceSyncTask::KEY, null, null, 1, 'manual_trigger');
    }

    public function test_sync_processing_order_status_command_is_a_compatibility_no_op(): void
    {
        $this->createServicesTable();
        $this->createOrdersTable();

        $service = Service::query()->create([
            'status' => ServiceStatus::ACTIVE,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORD-SCHEDULE-001',
            'service_id' => $service->id,
            'status' => OrderStatus::PROCESSING,
        ]);

        $this->artisan('orders:sync-processing-status')->assertExitCode(0);

        $this->assertSame(OrderStatus::PROCESSING, (int) $order->fresh()->status);
    }

    public function test_queue_backlog_worker_command_exits_cleanly_when_queue_is_empty(): void
    {
        $this->createJobsTable();

        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('queue.connections.database.queue', 'default');

        $this->artisan('queue:work', [
            '--queue' => 'provision,referral,notification,coupon,default',
            '--sleep' => 1,
            '--tries' => 3,
            '--timeout' => 1200,
            '--memory' => 2048,
            '--stop-when-empty' => true,
            '--max-time' => 50,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::connection('sqlite')->table('jobs')->count());
    }

    private function useSqliteInMemoryDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        app('db')->setDefaultConnection('sqlite');
        DB::connection('sqlite')->getPdo();
    }

    private function createSettingsTable(): void
    {
        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 100);
            $table->string('item_key', 100);
            $table->text('item_value')->nullable();
        });
    }

    private function createSuppliersTable(): void
    {
        Schema::connection('sqlite')->create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('interface_type')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });
    }

    private function createSupplierPluginBindingTables(): void
    {
        Schema::connection('sqlite')->create('integration_plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 32);
            $table->string('slug', 120);
            $table->string('plugin_key', 120);
            $table->string('name', 120);
            $table->string('version', 32)->default('1.0.0');
            $table->string('entry_class', 255);
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('supplier_plugin_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('plugin_id');
            $table->string('provider_key', 120);
            $table->string('environment', 30)->default('production');
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    private function createServicesTable(): void
    {
        Schema::connection('sqlite')->create('services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('trace_id')->nullable();
            $table->timestamps();
        });
    }

    private function createOrdersTable(): void
    {
        Schema::connection('sqlite')->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();
        });
    }

    private function createJobsTable(): void
    {
        Schema::connection('sqlite')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}

final class FakeScheduledAuthRefreshDriver implements UpstreamDriver
{
    public function __construct(
        private readonly RecordingScheduledAuthRefresh $scheduledAuthRefresh,
        private readonly string $key = ProviderKey::HOSTING_PANEL_API,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake Hosting Panel API';
    }

    public function capabilities(): array
    {
        return [ProvidesScheduledAuthRefresh::class];
    }

    public function supports(string $capability): bool
    {
        return $capability === ProvidesScheduledAuthRefresh::class;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->scheduledAuthRefresh : null;
    }
}

final class RecordingScheduledAuthRefresh implements ProvidesScheduledAuthRefresh
{
    /**
     * @var list<int>
     */
    public array $supplierStatuses = [];

    public function refreshJwt(Supplier $supplier): string
    {
        $this->supplierStatuses[] = (int) $supplier->status;

        return 'test-jwt';
    }
}
