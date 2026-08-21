<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\Adapters\PluginSmsDriver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Sms\SmsDriverManager;
use App\Support\SmsTemplateCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Sms\Stay33\Lib\Stay33SmsClient;
use TuraIDC\Plugins\Sms\Stay33\Stay33Plugin;

class Stay33SmsPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPluginTables();
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    public function test_manifest_registers_stay33_sms_driver(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('sms', 'stay33', $this->pluginConfig());

        $this->app->forgetInstance(SmsDriverManager::class);

        $driver = app(SmsDriverManager::class)->resolve('stay33');
        $schemaKeys = collect(app(PluginScanner::class)->requireManifest('sms', 'stay33')->configSchema)
            ->pluck('key')
            ->all();

        $this->assertSame(PluginSmsDriver::class, $driver::class);
        $this->assertTrue(class_exists(Stay33Plugin::class));
        $this->assertTrue(class_exists(Stay33SmsClient::class));
        $this->assertSame([['value' => 'stay33', 'label' => 'MC云短信']], app(SmsDriverManager::class)->options());
        $this->assertContains('username', $schemaKeys);
        $this->assertContains('api_key', $schemaKeys);
        $this->assertNotContains('template_code', $schemaKeys);
        $this->assertNotContains('template_content', $schemaKeys);
        $this->assertNotContains('channel', $schemaKeys);
        $this->assertNotContains('api_endpoint', $schemaKeys);
        $this->assertNotContains('backup_endpoint', $schemaKeys);
        $this->assertNotContains('timeout_seconds', $schemaKeys);
    }

    public function test_runtime_sends_verify_code_to_documented_api(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('sms', 'stay33', $this->pluginConfig());

        Http::fake([
            'https://idc.stay33.cn/sms/sendApi.php' => Http::response([
                'code' => 1,
                'msg' => '全部短信发送成功',
                'success_data' => [
                    [
                        'phone' => '18888888888',
                        'sms_code' => 'Sms_20251012131452041',
                        'used_quota' => 1,
                        'remaining_quota' => 1000,
                        'send_time' => '2025-10-12 13:14:52',
                        'channel_name' => 'MC云短信',
                        'sign_name' => 'MCloud',
                    ],
                ],
            ], 200),
        ]);

        $result = app(PluginRuntimeRegistry::class)->execute(
            domain: 'sms',
            slugOrKey: 'stay33',
            action: 'sms.send_verify_code',
            payload: [
                'phone' => '18888888888',
                'code' => '131452',
                'options' => ['template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE],
            ],
        );

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('success', $result['data']['status'] ?? null);
        $this->assertSame('Sms_20251012131452041', $result['data']['request_id'] ?? null);
        $this->assertSame(SmsTemplateCatalog::TEMPLATE_VERIFY_CODE, $result['data']['template_code'] ?? null);
        $this->assertSame([
            'code' => '131452',
            'sign_name' => 'MCloud',
            'channel' => '1',
        ], $result['data']['template_params'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://idc.stay33.cn/sms/sendApi.php'
                && $request['username'] === 'turaidc-user'
                && $request['key'] === 'sk_live_test'
                && $request['phone'] === '18888888888'
                && $request['content'] === '【MCloud】您的验证码是：131452。'
                && $request['channel'] === '1';
        });
    }

    public function test_runtime_sends_message_with_sign_to_documented_api(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('sms', 'stay33', $this->pluginConfig());

        Http::fake([
            'https://idc.stay33.cn/sms/sendApi.php' => Http::response([
                'code' => 1,
                'msg' => '全部短信发送成功',
                'success_data' => [
                    [
                        'phone' => '18888888888',
                        'sms_code' => 'Sms_20251012131452041',
                        'used_quota' => 1,
                        'remaining_quota' => 1000,
                        'send_time' => '2025-10-12 13:14:52',
                        'channel_name' => 'MC云短信',
                        'sign_name' => 'MCloud',
                    ],
                ],
            ], 200),
        ]);

        $result = app(PluginRuntimeRegistry::class)->execute(
            domain: 'sms',
            slugOrKey: 'stay33',
            action: 'sms.send_message',
            payload: [
                'phone' => '18888888888',
                'template_code' => '100001',
                'content' => '您的验证码是：{codes}131452{/codes}。',
                'options' => [],
            ],
        );

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('success', $result['data']['status'] ?? null);
        $this->assertSame('Sms_20251012131452041', $result['data']['request_id'] ?? null);
        $this->assertSame('100001', $result['data']['template_code'] ?? null);
        $this->assertSame('【MCloud】您的验证码是：131452。', $result['data']['template_params']['content'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://idc.stay33.cn/sms/sendApi.php'
                && $request['username'] === 'turaidc-user'
                && $request['key'] === 'sk_live_test'
                && $request['phone'] === '18888888888'
                && $request['content'] === '【MCloud】您的验证码是：131452。'
                && $request['channel'] === '1';
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function pluginConfig(): array
    {
        return [
            'username' => 'turaidc-user',
            'api_key' => 'sk_live_test',
            'sign_name' => 'MCloud',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function activatePlugin(string $domain, string $slug, array $config): IntegrationPlugin
    {
        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);

        $manifest = $scanner->requireManifest($domain, $slug);
        $plugin = $installer->install($domain, $slug);
        $configRepository->save($plugin, $manifest, $config);

        return $installer->enable($plugin);
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

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        if (Schema::hasTable('integration_plugin_bindings')) {
            DB::table('integration_plugin_bindings')->truncate();
        }
        if (Schema::hasTable('integration_plugin_configs')) {
            DB::table('integration_plugin_configs')->truncate();
        }
        DB::table('integration_plugins')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
