<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\Automation\Heartbeat\ScheduledTaskValidator;
use App\Services\Integrations\Plugins\Adapters\PluginUpstreamDriver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginProviderRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\UpstreamBillingRestoreProfile;
use App\Services\Upstream\Data\UpstreamProviderDescriptor;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\Support\WebSessionCookieParser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreProfile;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceAdapter;

class ZjmfServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureZjmfPluginEnabled();
    }

    public function test_it_registers_zjmf_driver_through_upstream_plugin(): void
    {
        $registry = app(ProviderRegistry::class);
        $driver = $registry->find(ProviderKey::ZJMF_FINANCE_API);

        $this->assertInstanceOf(PluginUpstreamDriver::class, $driver);
        $this->assertSame('ZJMF 财务接口', $driver?->label());
        $this->assertInstanceOf(
            ZjmfFinanceAdapter::class,
            $driver?->resolve(ProvidesConsoleCatalog::class)
        );
    }

    public function test_zjmf_adapter_declares_platform_used_methods_explicitly_without_dynamic_forwarding(): void
    {
        $reflection = new \ReflectionClass(ZjmfFinanceAdapter::class);

        foreach ([
            'login',
            'refreshJwt',
            'loginResponse',
            'getUserProfile',
            'getBalance',
            'getProductCatalog',
            'getProductConfigTemplate',
            'fetchRealConfigOptions',
            'fetchBatchProductConfigOptions',
            'fetchBatchProductStocks',
            'provisionOrder',
            'getProductProvisionConfig',
            'renewHost',
            'renewServiceInvoice',
            'recoverRenewInvoice',
            'recoverRenewInvoiceWithContext',
            'syncServiceStatuses',
            'getHostDetail',
            'getVncUrl',
            'powerAction',
            'getModuleStatus',
            'getReinstallOptions',
            'resetPassword',
            'reinstall',
            'getSupportedModules',
            'fetchCustomModulePage',
            'getHostUpgradeConfigOptions',
            'previewHostConfigUpgrade',
            'checkoutHostConfigUpgrade',
            'getHostUpgradePromoPreview',
            'removeHostUpgradePromoCode',
            'getHostUpgradeOptions',
            'previewHostUpgrade',
            'applyHostUpgradePromoCode',
            'checkoutHostUpgrade',
            'buyFlowPacket',
            'fundInvoice',
            'purchaseTrafficPackage',
            'purchaseHostUpgrade',
            'submitCustomModuleAction',
            'getCustomModuleActionEndpoint',
            'post',
            'get',
            'getText',
            'parallelGet',
            'put',
            'delete',
            'requestText',
            'request',
        ] as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method) && $reflection->getMethod($method)->getDeclaringClass()->getName() === ZjmfFinanceAdapter::class,
                "ZjmfFinanceAdapter must explicitly declare {$method}()."
            );
        }

        $this->assertFalse($reflection->hasMethod('__call'));
    }

    public function test_provider_registry_exports_driver_metadata_without_provider_key_labels(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, ProviderKey::label(ProviderKey::ZJMF_FINANCE_API));
        $this->assertContains(ProviderKey::ZJMF_FINANCE_API, $registry->keys());
        $option = collect($registry->options())
            ->firstWhere('value', ProviderKey::ZJMF_FINANCE_API);
        $this->assertIsArray($option);
        $this->assertSame('ZJMF 财务接口', $option['label']);
        $this->assertArrayHasKey('supplier_form', $option);

        $descriptor = collect($registry->descriptors())
            ->first(fn (UpstreamProviderDescriptor $item): bool => $item->key === ProviderKey::ZJMF_FINANCE_API);

        $this->assertInstanceOf(UpstreamProviderDescriptor::class, $descriptor);
        $descriptorPayload = $descriptor->toArray();
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $descriptorPayload['key']);
        $this->assertSame('ZJMF 财务接口', $descriptorPayload['label']);
        $this->assertSame($descriptor->capabilities, $descriptorPayload['capabilities']);
        $this->assertArrayHasKey('supplier_form', $descriptorPayload);
        $this->assertContains(ProvidesConsoleCatalog::class, $descriptor->capabilities);
    }

    public function test_it_registers_zjmf_legacy_password_verifier_in_chain(): void
    {
        $needsRehash = false;
        $matched = app(LegacyPasswordVerifier::class)->verify(
            'Secret123',
            '###'.md5('Secret123'),
            $needsRehash
        );

        $this->assertTrue($matched);
        $this->assertTrue($needsRehash);
    }

    public function test_it_registers_zjmf_billing_restore_module(): void
    {
        $profile = app(UpstreamBillingRestoreProfile::class);

        $this->assertInstanceOf(ZjmfBillingRestoreProfile::class, $profile);
        $this->assertInstanceOf(ZjmfBillingRestoreService::class, app(ZjmfBillingRestoreService::class));
        $this->assertSame('RESTORE_ZJMF_BILLING', $profile->defaultConfirmationPhrase());
        $this->assertContains('RESTORE_ZJMF_BILLING', $profile->confirmationPhrases());
    }

    public function test_install_rejects_manifest_class_outside_plugin_directory(): void
    {
        $manifest = new PluginManifest(
            domain: 'test',
            slug: 'outside-'.bin2hex(random_bytes(3)),
            key: 'test_outside_class',
            name: '越界类插件',
            version: '1.0.0',
            // 指向系统类而非插件目录内类，必须被安装/启用校验拒绝。
            entryClass: SettingService::class,
            providerClass: null,
            capabilities: [],
            configSchema: [],
            basePath: base_path('plugins/addons/demo_style'),
        );

        $scanner = $this->createMock(PluginScanner::class);
        $scanner->method('requireManifest')->willReturn($manifest);

        $installer = new PluginInstaller(
            $scanner,
            $this->createMock(PluginFileLoader::class),
            app(PluginConfigRepository::class),
            app(PluginProviderRegistry::class),
            app(ScheduledTaskValidator::class),
            app(),
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('插件目录内');

        $installer->install('test', $manifest->slug);
    }

    public function test_plugin_manifest_hash_is_recorded_on_install(): void
    {
        $hash = DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('slug', 'zjmf_finance')
            ->value('manifest_hash');

        $this->assertIsString($hash);
        $this->assertSame(64, strlen((string) $hash));
    }

    public function test_plugin_manifest_tampering_is_logged(): void
    {
        DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('slug', 'zjmf_finance')
            ->update(['manifest_hash' => str_repeat('0', 64)]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, '被篡改'));

        app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance');
    }

    public function test_plugin_provider_cannot_register_system_level_routes(): void
    {
        $registry = new PluginProviderRegistry(
            app(),
            $this->createMock(PluginScanner::class),
            $this->createMock(PluginFileLoader::class),
        );

        $manifest = new PluginManifest(
            domain: 'test',
            slug: 'system-route-'.bin2hex(random_bytes(3)),
            key: 'test_system_route',
            name: '系统路由插件',
            version: '1.0.0',
            entryClass: self::class,
            providerClass: FakeSystemRouteProvider::class,
            capabilities: [],
            configSchema: [],
            basePath: base_path('plugins'),
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('系统级路由');

        $registry->activate($manifest);
    }

    public function test_plugin_provider_cannot_register_system_level_schedule(): void
    {
        $registry = new PluginProviderRegistry(
            app(),
            $this->createMock(PluginScanner::class),
            $this->createMock(PluginFileLoader::class),
        );

        $manifest = new PluginManifest(
            domain: 'test',
            slug: 'system-schedule-'.bin2hex(random_bytes(3)),
            key: 'test_system_schedule',
            name: '系统调度插件',
            version: '1.0.0',
            entryClass: self::class,
            providerClass: FakeSystemScheduleProvider::class,
            capabilities: [],
            configSchema: [],
            basePath: base_path('plugins'),
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('系统级调度');

        $registry->activate($manifest);
    }

    private function ensureZjmfPluginEnabled(): void
    {
        $this->ensurePluginTables();

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $scanner->requireManifest('upstream', 'zjmf_finance');
        $plugin = $installer->install('upstream', 'zjmf_finance');
        $installer->enable($plugin);

        $this->app->forgetInstance(ProviderRegistry::class);
        $this->app->forgetInstance(LegacyPasswordVerifier::class);
        $this->app->forgetInstance(WebSessionCookieParser::class);
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('manifest_hash', 64)->nullable()->after('version');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        } elseif (! Schema::hasColumn('integration_plugins', 'manifest_hash')) {
            Schema::table('integration_plugins', function (Blueprint $table): void {
                $table->string('manifest_hash', 64)->nullable()->after('version');
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }
    }
}

final class FakeSystemRouteProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->app->make(Router::class)->get('/fake-plugin-system-route', fn (): string => 'ok');
    }
}

final class FakeSystemScheduleProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        app(Schedule::class)->command('fake:plugin-system-schedule')->everyMinute();
    }
}
