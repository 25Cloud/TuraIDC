<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Certification\Smapi\Logic\Smapi;

class SmapiPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePluginTables();
        $this->cleanPluginTables();
        // 插件类走 PluginFileLoader 的 require_once 加载，不在 PSR-4 autoload 内，
        // 直接 new 之前必须先确保文件已载入。
        $this->loadSmapiPlugin();
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    private function loadSmapiPlugin(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'smapi');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
    }

    // ============================================================
    // certification.initialize
    // ============================================================

    public function test_execute_routes_initialize_action(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 200,
                'msg' => 'success',
                'data' => [
                    'id' => 'S-1001',
                    'certify_page_url' => 'https://smapi.x1m1.cn/certify/S-1001',
                ],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'return_url' => 'https://api.example.test/callback',
            ],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.initialize', $result['action']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('S-1001', $result['data']['certify_id']);
        $this->assertSame('https://smapi.x1m1.cn/certify/S-1001', $result['data']['raw']['certify_page_url']);
    }

    public function test_initialize_sends_expected_payload(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1002'],
            ], 200),
        ]);

        $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => [
                'real_name' => '李四',
                'id_card' => '110101199001010022',
                'return_url' => 'https://api.example.test/callback',
            ],
            'config' => $this->defaultConfig(),
        ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-App-Key', 'test-app-key')
                && $request->hasHeader('X-App-Secret', 'test-secret-key')
                && $request['product_code'] === 'alipay_v3'
                && $request['cert_name'] === '李四'
                && $request['cert_no'] === '110101199001010022'
                && $request['return_url'] === 'https://api.example.test/callback';
        });
    }

    public function test_initialize_uses_first_product_code_when_multiple(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1003'],
            ], 200),
        ]);

        $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'return_url' => '',
            ],
            'config' => array_merge($this->defaultConfig(), [
                'product_code' => 'alipay_v3,支付宝身份认证|tencent_sm,微信实名认证',
            ]),
        ]);

        Http::assertSent(fn ($request) => $request['product_code'] === 'alipay_v3');
    }

    public function test_initialize_returns_400_when_product_code_missing(): void
    {
        $plugin = new Smapi;

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011', 'return_url' => ''],
            'config' => array_merge($this->defaultConfig(), ['product_code' => '']),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('请先配置产品标识 product_code', $result['data']['message']);
    }

    public function test_initialize_returns_400_on_business_failure(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 400,
                'msg' => '密钥校验失败',
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011', 'return_url' => ''],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('密钥校验失败', $result['data']['message']);
    }

    public function test_initialize_falls_back_to_safe_message_when_provider_text_has_technical_details(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 400,
                'msg' => 'invalid AppSecret: bad signature',
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011', 'return_url' => ''],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('实名认证接口配置错误，请联系管理员', $result['data']['message']);
    }

    public function test_initialize_returns_400_when_certify_id_missing(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 200,
                'data' => ['certify_page_url' => 'https://smapi.x1m1.cn/certify/x'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011', 'return_url' => ''],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('聚合实名平台返回异常：未返回认证标识', $result['data']['message']);
    }

    public function test_initialize_throws_when_credentials_missing(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/initialize*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1'],
            ], 200),
        ]);

        $this->expectException(BusinessException::class);

        $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => ['real_name' => '张三', 'id_card' => '110101199001010011', 'return_url' => ''],
            'config' => array_merge($this->defaultConfig(), ['app_key' => '']),
        ]);
    }

    // ============================================================
    // certification.scan_url
    // ============================================================

    public function test_execute_routes_scan_url_action(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1001/query*' => Http::response([
                'status' => 200,
                'data' => [
                    'id' => 'S-1001',
                    'status' => 'processing',
                    'certify_page_url' => 'https://smapi.x1m1.cn/certify/S-1001',
                ],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.scan_url',
            'payload' => ['certify_id' => 'S-1001'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.scan_url', $result['action']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('https://smapi.x1m1.cn/certify/S-1001', $result['data']['url']);
    }

    public function test_scan_url_falls_back_to_alternate_url_fields(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-2/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-2', 'status' => 'initialized', 'qrcode_url' => 'https://smapi.x1m1.cn/qr/S-2'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.scan_url',
            'payload' => ['certify_id' => 'S-2'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('https://smapi.x1m1.cn/qr/S-2', $result['data']['url']);
    }

    public function test_scan_url_returns_400_when_url_missing(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-3/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-3', 'status' => 'processing'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.scan_url',
            'payload' => ['certify_id' => 'S-3'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(400, $result['data']['status']);
        $this->assertSame('获取认证链接失败，请联系管理员', $result['data']['message']);
    }

    // ============================================================
    // certification.query_status
    // ============================================================

    public function test_query_status_passed_maps_to_success(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'passed', 'passed_at' => '2026-08-01 10:00:00'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(1, $result['data']['status']);
        $this->assertSame('审核通过', $result['data']['message']);
    }

    public function test_query_status_failed_maps_to_failure_with_reason(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'failed', 'fail_reason' => '身份信息不一致'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(2, $result['data']['status']);
        $this->assertSame('身份信息不一致', $result['data']['message']);
    }

    public function test_query_status_failed_without_reason(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'failed'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(2, $result['data']['status']);
        $this->assertSame('审核未通过', $result['data']['message']);
    }

    public function test_query_status_updated_maps_to_failure(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'updated'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(2, $result['data']['status']);
    }

    public function test_query_status_processing_maps_to_pending(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'processing'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(4, $result['data']['status']);
        $this->assertSame('认证处理中，请稍后再获取结果', $result['data']['message']);
    }

    public function test_query_status_initialized_maps_to_pending(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 200,
                'data' => ['id' => 'S-1', 'status' => 'initialized'],
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(4, $result['data']['status']);
        $this->assertSame('待认证，请先完成扫码认证', $result['data']['message']);
    }

    public function test_query_status_network_error_maps_to_network_status(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(3, $result['data']['status']);
        $this->assertSame('实名认证接口请求失败，请稍后重试', $result['data']['message']);
    }

    public function test_query_status_business_failure_stays_pending(): void
    {
        $plugin = new Smapi;

        Http::fake([
            'smapi.x1m1.cn/api/realname/certifications/S-1/query*' => Http::response([
                'status' => 400,
                'msg' => '认证记录不存在',
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.query_status',
            'payload' => ['certify_id' => 'S-1'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertSame(4, $result['data']['status']);
        $this->assertSame('认证处理中', $result['data']['message']);
    }

    // ============================================================
    // certification.verify_callback / fee_config / 其他
    // ============================================================

    public function test_verify_callback_unsupported(): void
    {
        $plugin = new Smapi;

        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => ['payload' => [], 'headers' => []],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(40001, $result['data']['code']);
        $this->assertSame(501, $result['data']['http_status']);
    }

    public function test_execute_routes_fee_config_action(): void
    {
        $plugin = new Smapi;

        $result = $plugin->execute([
            'action' => 'certification.fee_config',
            'payload' => [],
            'config' => [
                'charge_enabled' => true,
                'amount' => 1.5,
                'free_times' => 3,
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['free_attempts']);
        $this->assertSame(1.5, $result['data']['retry_fee']);
        $this->assertTrue($result['data']['charge_enabled']);
    }

    public function test_execute_fee_config_defaults_when_no_config(): void
    {
        $plugin = new Smapi;

        $result = $plugin->execute([
            'action' => 'certification.fee_config',
            'payload' => [],
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['free_attempts']);
        $this->assertSame(0.0, $result['data']['retry_fee']);
        $this->assertFalse($result['data']['charge_enabled']);
    }

    public function test_execute_unknown_action_returns_unsupported(): void
    {
        $plugin = new Smapi;

        $result = $plugin->execute([
            'action' => 'certification.unknown',
            'payload' => [],
            'config' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('certification.unknown', $result['action']);
    }

    public function test_plugin_key_and_label(): void
    {
        $plugin = new Smapi;

        $this->assertSame('smapi', $plugin->key());
        $this->assertSame('聚合实名认证', $plugin->label());
    }

    // ============================================================
    // helpers
    // ============================================================

    private function defaultConfig(): array
    {
        return [
            'api_url' => 'https://smapi.x1m1.cn',
            'app_key' => 'test-app-key',
            'secret_key' => 'test-secret-key',
            'product_code' => 'alipay_v3,支付宝身份认证',
            'ssl_verify' => true,
        ];
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
