<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Http\Resources\Product\SupplierResource;
use App\Jobs\DeliverTicketReplyToUpstreamJob;
use App\Jobs\DeliverTicketToUpstreamJob;
use App\Jobs\ProcessPaidOrderFulfillmentJob;
use App\Jobs\ProcessPaidOrderReferralRewardJob;
use App\Jobs\RunScheduleTaskJob;
use App\Jobs\SendClientLoginEmailAlertJob;
use App\Jobs\SendClientLoginFailureEmailAlertJob;
use App\Jobs\SendPaidInvoiceAdminNotificationJob;
use App\Jobs\SendTicketNotificationEmailJob;
use App\Jobs\SyncInvoiceCouponUsageJob;
use App\Jobs\SyncPaidInvoiceCouponUsageJob;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\System\SettingService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Http\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackendHealthFixRegressionTest extends TestCase
{
    public function test_plugin_secret_setting_is_not_returned_from_settings_api_payload(): void
    {
        Setting::setValue('payment', 'alipay_private_key', 'plain-private-key');

        $payload = (new SettingService)->getGroupSettings('payment')
            ->pluck('key')
            ->all();

        $this->assertNotContains('alipay_private_key', $payload);
    }

    public function test_plugin_setting_keys_are_ignored_when_saving_settings_group(): void
    {
        DB::table('settings')
            ->where('group_key', 'payment')
            ->whereIn('item_key', ['alipay_private_key', 'alipay_enabled'])
            ->delete();

        (new SettingService)->saveGroupSettings('payment', [
            'alipay_private_key' => 'plain-private-key',
            'alipay_enabled' => '1',
        ]);

        $this->assertDatabaseMissing('settings', [
            'group_key' => 'payment',
            'item_key' => 'alipay_private_key',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group_key' => 'payment',
            'item_key' => 'alipay_enabled',
        ]);
    }

    public function test_plugin_secret_setting_cannot_be_revealed_from_settings_api(): void
    {
        Setting::setValue('notification', 'email_password', 'mail-secret');

        $this->expectException(BusinessException::class);

        (new SettingService)->revealSensitiveSetting('notification', 'email_password');
    }

    public function test_supplier_resource_does_not_return_api_key(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => '测试供应商',
            'code' => 'supplier_test_'.$suffix,
            'status' => 1,
        ]);
        $this->syncSupplierBinding($supplier, 'secret-value');

        $payload = (new SupplierResource($supplier))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertTrue($payload['has_api_key']);
    }

    public function test_supplier_show_does_not_return_api_key(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => '测试供应商',
            'code' => 'supplier_test_'.$suffix,
            'status' => 1,
            'sort_order' => 0,
        ]);
        $this->syncSupplierBinding($supplier, 'secret-value');

        $payload = app(AdminConfigurationV2QueryService::class)->supplierDetail($supplier);

        $this->assertArrayNotHasKey('api_key', $payload['supplier']);
        $this->assertTrue($payload['supplier']['credentials']['api_credential_configured']);
    }

    public function test_supplier_api_key_can_be_revealed_on_demand(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => '测试供应商',
            'code' => 'supplier_test_'.$suffix,
            'status' => 1,
        ]);
        $this->syncSupplierBinding($supplier, 'supplier-secret');

        $payload = app(AdminConfigurationV2QueryService::class)->supplierSecret($supplier, 'api_key');

        $this->assertSame('api_key', $payload['key']);
        $this->assertSame('supplier-secret', $payload['value']);
    }

    public function test_provider_aware_cache_keys_keep_zjmf_independent(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Cache Supplier '.$suffix,
            'code' => 'zjmf-cache-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'sort_order' => 0,
        ]);
        $this->createSupplierPluginBinding($supplier, $this->ensureZjmfIntegrationPlugin());
        $supplierId = (int) $supplier->id;

        $this->assertSame(
            "upstream:zjmf_finance_api:product_config_options:{$supplierId}:123",
            app(ServiceTransformService::class)->buildProductConfigOptionsCacheKey($supplier, 123)
        );
        $this->assertSame(
            "upstream:zjmf_finance_api:host_modules:v2:{$supplierId}:456",
            app(ServiceDetailService::class)->buildMonitorModuleCacheKey($supplier, 456)
        );
        $this->assertSame(
            "upstream:zjmf_finance_api:reinstall_options:{$supplierId}:789",
            app(ServiceDetailService::class)->buildReinstallOptionsCacheKey($supplier, 789)
        );

        $this->assertStringNotContainsString(
            'buildLegacyProductConfigOptionsCacheKey',
            file_get_contents(base_path('app/Services/ClientServiceConsole/ServiceTransformService.php')) ?: ''
        );
        $this->assertStringNotContainsString(
            'buildLegacyMonitorModuleCacheKey',
            file_get_contents(base_path('app/Services/ClientServiceConsole/ServiceDetailService.php')) ?: ''
        );
        $this->assertStringNotContainsString(
            'buildLegacyReinstallOptionsCacheKey',
            file_get_contents(base_path('app/Services/ClientServiceConsole/ServiceDetailService.php')) ?: ''
        );
    }

    public function test_database_engineering_service_never_deletes_payments(): void
    {
        $source = file_get_contents(base_path('app/Services/System/DatabaseEngineeringService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("deleteOrphans('payments'", $source);
        $this->assertStringContainsString('payments_orphan_user_or_invoice_reported', $source);
    }

    public function test_get_group_settings_does_not_persist_fallback_values(): void
    {
        try {
            DB::table('settings')
                ->where('group_key', 'payment')
                ->where('item_key', 'alipay_enabled')
                ->delete();
            Cache::flush();

            (new SettingService)->getGroupSettings('payment');

            $this->assertDatabaseMissing('settings', [
                'group_key' => 'payment',
                'item_key' => 'alipay_enabled',
            ]);
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'Access denied')) {
                $this->markTestSkipped('测试库不可写，已跳过 GET 设置只读数据库断言。');
            }

            throw $exception;
        }
    }

    public function test_payment_service_never_deletes_balance_logs(): void
    {
        $source = file_get_contents(base_path('app/Services/Finance/PaymentService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('BalanceLog::query()->delete', $source);
        $this->assertStringNotContainsString('->delete();', $source);
    }

    public function test_alipay_refund_does_not_use_undefined_locked_invoice_variable(): void
    {
        $source = file_get_contents(base_path('app/Services/Finance/PaymentService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('$this->resolveBalanceRefundableAmount($invoice, $payment)', $source);
    }

    public function test_paid_order_jobs_define_runtime_policy(): void
    {
        $this->assertSame(1200, (new ProcessPaidOrderFulfillmentJob(1))->timeout);
        $this->assertSame(300, (new ProcessPaidOrderReferralRewardJob(1))->timeout);
        $this->assertSame(300, (new SendPaidInvoiceAdminNotificationJob(1))->timeout);
        $this->assertSame(300, (new SyncPaidInvoiceCouponUsageJob(1))->timeout);
    }

    public function test_invoice_coupon_sync_job_defines_transaction_and_overlap_policy(): void
    {
        $job = new SyncInvoiceCouponUsageJob(42);

        $this->assertSame('coupon', $job->queue);
        $this->assertTrue((bool) $job->afterCommit);
        $this->assertSame([30, 120, 300], $job->backoff);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('job:invoice-coupon:42', $middleware[0]->key);
        $this->assertSame(10, $middleware[0]->releaseAfter);
        $this->assertSame(600, $middleware[0]->expiresAfter);
    }

    public function test_ticket_upstream_jobs_define_unique_overlap_and_retry_policy(): void
    {
        $ticketJob = new DeliverTicketToUpstreamJob(42);
        $replyJob = new DeliverTicketReplyToUpstreamJob(84);

        foreach ([
            [$ticketJob, 'job:ticket-upstream:create:42'],
            [$replyJob, 'job:ticket-upstream:reply:84'],
        ] as [$job, $lockKey]) {
            $this->assertSame(120, $job->timeout);
            $this->assertSame(900, $job->uniqueFor);
            $this->assertSame([30, 120, 300], $job->backoff);
            $this->assertSame($job === $ticketJob ? '42' : '84', $job->uniqueId());
            $this->assertCount(1, $job->middleware());
            $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
            $this->assertSame($lockKey, $job->middleware()[0]->key);
            $this->assertSame(10, $job->middleware()[0]->releaseAfter);
            $this->assertSame(900, $job->middleware()[0]->expiresAfter);
        }
    }

    public function test_queue_jobs_define_timeout_policy(): void
    {
        $jobs = [
            new ProcessPaidOrderFulfillmentJob(1),
            new ProcessPaidOrderReferralRewardJob(1),
            new RunScheduleTaskJob('service-status-sync'),
            new SendClientLoginEmailAlertJob(1, 'client@example.test', '客户', '2026-07-02 12:00:00', '127.0.0.1'),
            new SendClientLoginFailureEmailAlertJob(1, 'client@example.test', '客户', 'client@example.test', '2026-07-02 12:00:00', '127.0.0.1'),
            new SendPaidInvoiceAdminNotificationJob(1),
            new SendTicketNotificationEmailJob('admin@example.test', 'ticket_created'),
            new SyncInvoiceCouponUsageJob(1),
            new SyncPaidInvoiceCouponUsageJob(1),
        ];

        foreach ($jobs as $job) {
            $this->assertObjectHasProperty('timeout', $job, $job::class.' must define a queue timeout.');
            $this->assertGreaterThan(0, $job->timeout, $job::class.' timeout must be positive.');
        }
    }

    public function test_schedule_worker_consumes_declared_queues_with_timeout(): void
    {
        $source = file_get_contents(app_path('Services/Automation/Heartbeat/QueueDrainService.php'));

        $this->assertIsString($source);
        $this->assertSame('provision,referral,notification,coupon,default', config('queue.turaidc_business_queues'));
        $this->assertSame('automation', config('queue.turaidc_schedule_queue'));
        $this->assertStringContainsString('queue.turaidc_business_queues', $source);
        $this->assertStringContainsString('queue.turaidc_schedule_queue', $source);
        $this->assertStringContainsString('queue:work', $source);
        $this->assertStringContainsString('runWorkersInParallel', $source);
        $this->assertStringContainsString('queue.turaidc_worker_timeout', $source);

        $environmentTemplate = file_get_contents(base_path('.env.example'));
        $this->assertIsString($environmentTemplate);
        $this->assertStringContainsString('TURAIDC_BUSINESS_QUEUES=', $environmentTemplate);
        $this->assertStringContainsString('TURAIDC_SCHEDULE_QUEUE=automation', $environmentTemplate);
    }

    public function test_sentry_is_configurable_without_enabling_default_pii(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString('SentryIntegration::handles($exceptions)', $bootstrap);
        $composer = json_decode(
            file_get_contents(base_path('composer.json')) ?: '{}',
            true
        );

        $this->assertArrayHasKey('sentry/sentry-laravel', (array) ($composer['require'] ?? []));

        $config = require base_path('config/sentry.php');

        $this->assertSame('', (string) ($config['dsn'] ?? ''));
        $this->assertFalse((bool) ($config['send_default_pii'] ?? true));
        $this->assertContains('/up', (array) ($config['ignore_transactions'] ?? []));
    }

    public function test_vnc_relay_log_masks_token(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/VncRelayCommand.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'token' => \$this->maskToken(\$token)", $source);
        // 上游连接失败日志中的 path 必须经 maskUpstreamPath 脱敏，禁止原样记录携带
        // password/token 的 query（回归：VNC 密码以明文落入日志）。
        $this->assertStringContainsString("'path' => \$this->maskUpstreamPath", $source);
    }

    public function test_sync_upstream_command_targets_service_detail_service_directly(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/SyncUpstreamServicesCommand.php'));

        $this->assertIsString($source);
        // fetchRemoteState/syncServiceFromRemote 只定义在 ServiceDetailService 上，
        // 命令必须注入子服务直接调用，禁止对纯门面 ClientServiceConsoleService 反射（会必然失败）。
        $this->assertStringContainsString('use App\Services\ClientServiceConsole\ServiceDetailService;', $source);
        $this->assertStringContainsString('ServiceDetailService $detailService', $source);
        $this->assertStringNotContainsString("getMethod('fetchRemoteState')", $source);
    }

    public function test_validation_language_uses_simplified_chinese(): void
    {
        $messages = require base_path('lang/zh_CN/validation.php');

        $this->assertSame('请填写:attribute。', $messages['required']);
        $this->assertSame('真实姓名', $messages['attributes']['realname']);
        $this->assertSame('证件号码', $messages['attributes']['idcard']);
    }

    private function ensureZjmfIntegrationPlugin(): int
    {
        DB::table('integration_plugins')->updateOrInsert([
            'domain' => 'upstream',
            'plugin_key' => ProviderKey::ZJMF_FINANCE_API,
        ], [
            'slug' => 'zjmf_finance',
            'name' => 'ZJMF 财务接口',
            'version' => '1.0.0',
            'provider_class' => null,
            'entry_class' => 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\ZjmfFinancePlugin',
            'capabilities_json' => json_encode([]),
            'config_schema_json' => json_encode([]),
            'status' => 1,
            'installed_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');
    }

    private function createSupplierPluginBinding(Supplier $supplier, int $pluginId): void
    {
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
    }

    private function syncSupplierBinding(Supplier $supplier, string $apiKey): void
    {
        $this->ensureZjmfIntegrationPlugin();
        app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://supplier.example.com',
            'account_name' => 'demo',
            'api_key' => $apiKey,
            'provider_config' => [],
            'status' => 1,
            'priority' => 0,
        ]);
    }
}
