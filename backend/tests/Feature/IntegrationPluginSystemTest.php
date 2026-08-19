<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\V2\IntegrationPlugin\RunIntegrationPluginTaskRequest;
use App\Models\IntegrationPlugin;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Integrations\Plugins\IntegrationPluginService;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\SmsDriverManager;
use App\Support\SmsTemplateCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Factory;
use Tests\TestCase;

class IntegrationPluginSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePluginTables();
        $this->cleanPluginTables();
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    // ============================================================
    // 实名认证域测试
    // ============================================================

    public function test_verification_test_creates_task(): void
    {
        $plugin = $this->activatePlugin('verification', 'demo_verification', [
            'api_url' => 'https://example.test',
            'app_id' => 'demo_app',
        ]);

        $result = app(IntegrationPluginService::class)->testVerification($plugin, [
            'real_name' => '张三',
            'card_no' => '110101199001010011',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.initialize', $result['action']);
        $this->assertSame(200, $result['data']['status'] ?? null);
        $this->assertNotSame('', $result['data']['certify_id'] ?? '');
    }

    public function test_verification_test_rejects_wrong_domain(): void
    {
        $plugin = $this->activatePlugin('mail', 'demo_mail', [
            'from_address' => 'noreply@test.example',
            'from_name' => 'Test Mailer',
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('实名认证');

        app(IntegrationPluginService::class)->testVerification($plugin, [
            'real_name' => '张三',
            'card_no' => '110101199001010011',
        ]);
    }

    public function test_verification_test_rejects_disabled_plugin(): void
    {
        $plugin = $this->installPlugin('verification', 'demo_verification');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('插件未启用');

        app(IntegrationPluginService::class)->testVerification($plugin, [
            'real_name' => '张三',
            'card_no' => '110101199001010011',
        ]);
    }

    public function test_health_check_rejects_unconfigured_plugin(): void
    {
        $plugin = $this->installPlugin('verification', 'demo_verification');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('插件配置缺少必填项');

        app(IntegrationPluginService::class)->healthCheck($plugin);
    }

    // ============================================================
    // 支付域测试
    // ============================================================

    public function test_payment_test_precreates_order(): void
    {
        $plugin = $this->activatePlugin('payment', 'demo_pay', [
            'merchant_id' => 'demo_merchant',
            'enabled' => true,
        ]);

        $this->forgetRuntimeInstances();

        $result = app(IntegrationPluginService::class)->testPayment($plugin, []);

        $this->assertTrue($result['success']);
        $this->assertSame('payment.test', $result['action']);
        $this->assertStringStartsWith('TEST', $result['data']['out_trade_no'] ?? '');
        $this->assertNotSame('', $result['data']['qr_code'] ?? '');
    }

    public function test_payment_test_rejects_wrong_domain(): void
    {
        $plugin = $this->activatePlugin('sms', 'demo_sms', [
            'access_key' => 'demo_ak',
            'sign_name' => '测试签名',
            'template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('支付渠道');

        app(IntegrationPluginService::class)->testPayment($plugin, []);
    }

    public function test_mail_test_uses_system_mail_driver(): void
    {
        $plugin = $this->activatePlugin('mail', 'demo_mail', [
            'from_address' => 'noreply@test.example',
            'from_name' => 'Test Mailer',
        ]);
        $this->forgetRuntimeInstances();

        $result = app(IntegrationPluginService::class)->testEmail($plugin, [
            'account_index' => 0,
            'to' => 'user@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('mail.test_smtp', $result['action']);
        $this->assertTrue($result['data']['sent'] ?? false);
        $this->assertSame('user@example.com', $result['data']['to'] ?? '');
    }

    public function test_sms_test_uses_system_sms_driver(): void
    {
        $plugin = $this->activatePlugin('sms', 'demo_sms', [
            'access_key' => 'demo_ak',
            'sign_name' => '测试签名',
            'template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
        ]);
        $this->forgetRuntimeInstances();

        $result = app(IntegrationPluginService::class)->testSms($plugin, [
            'phone' => '13800138000',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('sms.test', $result['action']);
        $this->assertTrue($result['data']['sent'] ?? false);
        $this->assertSame('success', $result['data']['status'] ?? '');
        $this->assertSame('13800138000', $result['data']['phone'] ?? '');
    }

    // ============================================================
    // 人机验证域测试
    // ============================================================

    public function test_captcha_test_passes_on_success(): void
    {
        $plugin = $this->activatePlugin('captcha', 'geetest', [
            'captcha_id' => 'test-captcha-id',
            'captcha_key' => 'test-captcha-key',
        ]);

        Http::fake([
            'gcaptcha4.geetest.com/validate' => Http::response(['result' => 'success'], 200),
        ]);

        $result = app(IntegrationPluginService::class)->testCaptcha($plugin, [
            'lot_number' => 'lot-1',
            'captcha_output' => 'output-1',
            'pass_token' => 'token-1',
            'gen_time' => '1700000000',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['verified'] ?? false);
    }

    public function test_captcha_test_fails_on_reject(): void
    {
        $plugin = $this->activatePlugin('captcha', 'geetest', [
            'captcha_id' => 'test-captcha-id',
            'captcha_key' => 'test-captcha-key',
        ]);

        Http::fake([
            'gcaptcha4.geetest.com/validate' => Http::response(['result' => 'fail'], 200),
        ]);

        $result = app(IntegrationPluginService::class)->testCaptcha($plugin, [
            'lot_number' => 'lot-1',
            'captcha_output' => 'output-1',
            'pass_token' => 'token-1',
            'gen_time' => '1700000000',
        ]);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['data']['verified'] ?? true);
    }

    public function test_captcha_test_rejects_wrong_domain(): void
    {
        $plugin = $this->activatePlugin('mail', 'demo_mail', [
            'from_address' => 'noreply@test.example',
            'from_name' => 'Test Mailer',
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('人机验证');

        app(IntegrationPluginService::class)->testCaptcha($plugin, []);
    }

    // ============================================================
    // 上游域测试
    // ============================================================

    public function test_connection_test_resolves_capability(): void
    {
        $plugin = $this->activatePlugin('upstream', 'demo_servers', []);

        $result = app(IntegrationPluginService::class)->testConnection($plugin, []);

        $this->assertTrue($result['success']);
        $this->assertSame('upstream.test', $result['action']);
        $this->assertTrue($result['data']['healthy'] ?? false);
        $this->assertNotSame('', $result['data']['capability'] ?? '');
    }

    public function test_connection_test_rejects_wrong_domain(): void
    {
        $plugin = $this->activatePlugin('sms', 'demo_sms', [
            'access_key' => 'demo_ak',
            'sign_name' => '测试签名',
            'template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('上游开通');

        app(IntegrationPluginService::class)->testConnection($plugin, []);
    }

    public function test_verification_test_requires_real_name_and_card_no(): void
    {
        $request = new RunIntegrationPluginTaskRequest;
        $request->replace([
            'type' => 'test_verification',
            'payload' => ['real_name' => '张三'],
        ]);

        $validator = app(Factory::class)->make($request->input(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payload.card_no', $validator->errors()->toArray());
    }

    public function test_captcha_test_requires_full_geetest_output(): void
    {
        $request = new RunIntegrationPluginTaskRequest;
        $request->replace([
            'type' => 'test_captcha',
            'payload' => ['lot_number' => 'lot-1'],
        ]);

        $validator = app(Factory::class)->make($request->input(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payload.captcha_output', $validator->errors()->toArray());
        $this->assertArrayHasKey('payload.pass_token', $validator->errors()->toArray());
        $this->assertArrayHasKey('payload.gen_time', $validator->errors()->toArray());
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function forgetRuntimeInstances(): void
    {
        app()->forgetInstance(IntegrationPluginService::class);
        app()->forgetInstance(PaymentGatewayManager::class);
        app()->forgetInstance(PaymentGatewayRegistry::class);
        app()->forgetInstance(MailDriverManager::class);
        app()->forgetInstance(SmsDriverManager::class);
    }

    private function installPlugin(string $domain, string $slug): IntegrationPlugin
    {
        return app(PluginInstaller::class)->install($domain, $slug);
    }

    private function activatePlugin(string $domain, string $slug, array $config): IntegrationPlugin
    {
        $plugin = $this->installPlugin($domain, $slug);
        if ($config !== []) {
            app(PluginConfigRepository::class)->save(
                $plugin,
                app(PluginScanner::class)->requireManifest($domain, $slug),
                $config,
            );
        }

        return app(PluginInstaller::class)->enable($plugin);
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
                $table->string('manifest_hash', 64)->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->unsignedBigInteger('installed_by')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->unsignedBigInteger('enabled_by')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
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

        if (! Schema::hasTable('integration_plugin_bindings')) {
            Schema::create('integration_plugin_bindings', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->unsignedBigInteger('plugin_id');
                $table->string('binding_type', 50);
                $table->string('bindable_type', 120)->default('global');
                $table->unsignedBigInteger('bindable_id')->default(0);
                $table->string('binding_key', 120);
                $table->string('provider_key', 120)->nullable();
                $table->integer('priority')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->json('runtime_policy_json')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('backfill_batch_id', 64)->nullable();
                $table->timestamps();
                $table->unique(['domain', 'binding_type', 'bindable_type', 'bindable_id', 'binding_key'], 'plugin_bindings_unique');
            });
        }
    }

    private function cleanPluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        $isMysql = DB::getDriverName() === 'mysql';
        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach (
            [
                'integration_plugin_bindings',
                'integration_plugin_configs',
                'integration_plugin_runtime_logs',
                'product_upstream_bindings',
                'service_upstream_bindings',
                'supplier_plugin_bindings',
                'service_runtime_snapshots',
                'service_connection_snapshots',
                'service_provision_attempts',
            ] as $table
        ) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        DB::table('integration_plugins')->delete();

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
