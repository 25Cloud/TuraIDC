<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Certification\LeafFace\Logic\LeafFace;
use TuraIDC\Plugins\Certification\LeafFace\Logic\LeafFaceClient;

class LeafFacePluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPluginTables();
        // 插件类走 PluginFileLoader 的 require_once 加载，不在 PSR-4 autoload 内，
        // 直接 new 之前必须先确保文件已载入。
        $this->loadLeafFacePlugin();
    }

    private function loadLeafFacePlugin(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'leaf_face');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    // ============================================================
    // Unit tests: LeafFace execute() routing
    // ============================================================

    public function test_execute_routes_initialize_action(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response([
                'task_no' => 'VT202607041130001234ABCD',
                'task_id' => '81a4e811-53dd-4302-aac1-a18c9a8e8583',
                'verify_url' => '/h5/face?task_id=81a4e811-53dd-4302-aac1-a18c9a8e8583',
                'expires_in' => 7200,
            ], 200),
        ]);

        $plugin = new LeafFace;

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'cert_type' => 'IDENTITY_CARD',
                'return_url' => 'https://api.example.test/api/v2/client/verification/callback',
            ],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.initialize', $result['action']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('VT202607041130001234ABCD', $result['data']['certify_id']);

        Http::assertSent(function ($request): bool {
            $this->assertSame('application/json', $request->header('Content-Type')[0] ?? '');
            $this->assertSame('test-app-id', $request->header('X-App-Id')[0] ?? '');
            $this->assertNotSame('', $request->header('X-Timestamp')[0] ?? '');
            $this->assertNotSame('', $request->header('X-Nonce')[0] ?? '');
            $this->assertNotSame('', $request->header('X-Signature')[0] ?? '');
            $this->assertSame(hash('sha256', $request->body()), $request->header('X-Body-Sha256')[0] ?? '');

            return true;
        });
    }

    public function test_execute_routes_scan_url_action(): void
    {
        $this->seedVerifyUrlCache();

        $plugin = new LeafFace;

        $result = $plugin->execute([
            'action' => 'certification.scan_url',
            'payload' => ['certify_id' => 'VT202607041130001234ABCD'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.scan_url', $result['action']);
        $this->assertSame('https://face.ly-y.cn/h5/face?task_id=81a4e811-53dd-4302-aac1-a18c9a8e8583', $result['data']['url']);
    }

    public function test_execute_routes_fee_config_action(): void
    {
        $plugin = new LeafFace;

        $result = $plugin->execute([
            'action' => 'certification.fee_config',
            'payload' => [],
            'config' => [
                'charge_enabled' => true,
                'amount' => 3.5,
                'free_times' => 2,
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['free_attempts']);
        $this->assertSame(3.5, $result['data']['retry_fee']);
        $this->assertTrue($result['data']['charge_enabled']);
    }

    public function test_execute_fee_config_defaults_when_no_config(): void
    {
        $plugin = new LeafFace;

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

    public function test_execute_returns_error_for_unknown_action(): void
    {
        $plugin = new LeafFace;

        $result = $plugin->execute([
            'action' => 'certification.unknown',
            'payload' => [],
            'config' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Unsupported plugin action', $result['message']);
    }

    public function test_execute_handles_missing_action_key(): void
    {
        $plugin = new LeafFace;

        $result = $plugin->execute([
            'payload' => [],
            'config' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Unsupported plugin action', $result['message']);
    }

    // ============================================================
    // Unit tests: verifyCallback signature verification
    // ============================================================

    public function test_verify_callback_accepts_valid_signature(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body);

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame(200, $result['data']['http_status']);
        $this->assertSame('VT202607041130001234ABCD', $result['data']['certify_id']);
        $this->assertNotSame('', $result['data']['replay_key']);
    }

    public function test_verify_callback_rejects_wrong_signature(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body);
        $callback['headers']['x-leafsm-signature'] = str_repeat('0', 64);

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(401, $result['data']['http_status']);
    }

    public function test_verify_callback_rejects_stale_timestamp(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body, gmdate('c', time() - 600));

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(401, $result['data']['http_status']);
    }

    public function test_verify_callback_rejects_wrong_event(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body);
        $callback['headers']['x-leafsm-event'] = 'unexpected.event';

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(401, $result['data']['http_status']);
    }

    public function test_verify_callback_rejects_missing_signature_headers(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => [
                'payload' => $body,
                'headers' => [],
                'raw_body' => json_encode($body),
            ],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(401, $result['data']['http_status']);
    }

    public function test_verify_callback_rejects_tampered_body(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body);

        $tamperedBody = $this->callbackBody('VT202607041130009999ZZZZ');
        $tamperedRawBody = (string) json_encode($tamperedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $callback['payload'] = $tamperedBody;
        $callback['raw_body'] = $tamperedRawBody;
        $callback['headers']['x-body-sha256'] = hash('sha256', $tamperedRawBody);

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
        $this->assertSame(401, $result['data']['http_status']);
    }

    public function test_verify_callback_rejects_invalid_config(): void
    {
        $body = $this->callbackBody('VT202607041130001234ABCD');
        $callback = $this->signCallback($body);

        $plugin = new LeafFace;
        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => $callback,
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['passed']);
    }

    // ============================================================
    // Unit tests: LeafFaceClient initialize
    // ============================================================

    public function test_initialize_rejects_non_identity_card(): void
    {
        $client = new LeafFaceClient($this->defaultConfig());

        $result = $client->initialize('张三', '110101199001010011', 'PASSPORT', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('仅支持大陆身份证', $result['message']);
    }

    public function test_initialize_returns_mismatch_error(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response([
                'code' => 'TWO_FACTOR_MISMATCH',
                'message' => '姓名和身份证号不匹配',
            ], 422),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001011234', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('二要素预校验未通过', $result['message']);
    }

    public function test_initialize_returns_insufficient_credit_error(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response([
                'code' => 'INSUFFICIENT_CREDIT',
                'message' => 'insufficient credit',
            ], 402),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('额度不足', $result['message']);
    }

    public function test_initialize_returns_error_when_task_creation_fails(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response([
                'code' => 'TASK_CREATE_FAILED',
                'message' => 'task create failed',
            ], 500),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('任务创建失败', $result['message']);
    }

    public function test_initialize_returns_error_when_task_no_missing(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response([
                'task_id' => '81a4e811-53dd-4302-aac1-a18c9a8e8583',
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('联系管理员', $result['message']);
    }

    public function test_initialize_throws_when_app_id_missing(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('AppId');

        $client = new LeafFaceClient([
            'app_secret' => 'test-app-secret',
            'api_base_url' => 'https://face.ly-y.cn',
        ]);
        $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');
    }

    public function test_initialize_throws_when_app_secret_missing(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('AppSecret');

        $client = new LeafFaceClient([
            'app_id' => 'test-app-id',
            'api_base_url' => 'https://face.ly-y.cn',
        ]);
        $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');
    }

    public function test_initialize_returns_error_on_http_failure(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks' => Http::response('not json', 500),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('联系管理员', $result['message']);
    }

    // ============================================================
    // Unit tests: LeafFaceClient generateScanUrl
    // ============================================================

    public function test_generate_scan_url_rejects_empty_certify_id(): void
    {
        $client = new LeafFaceClient($this->defaultConfig());

        $result = $client->generateScanUrl('');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('已失效', $result['message']);
    }

    public function test_generate_scan_url_rejects_missing_cached_verify_url(): void
    {
        $client = new LeafFaceClient($this->defaultConfig());

        $result = $client->generateScanUrl('VT-NOT-CACHED');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('认证链接已失效', $result['message']);
    }

    public function test_generate_scan_url_uses_full_verify_url_as_is(): void
    {
        Cache::put(
            'leaf_face_verification:verify_url:'.hash('sha256', 'VT-ABS'),
            'https://face.ly-y.cn/h5/face?task_id=81a4e811-53dd-4302-aac1-a18c9a8e8583',
            now()->addMinute()
        );

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->generateScanUrl('VT-ABS');

        $this->assertSame(200, $result['status']);
        $this->assertSame('https://face.ly-y.cn/h5/face?task_id=81a4e811-53dd-4302-aac1-a18c9a8e8583', $result['url']);
    }

    // ============================================================
    // Unit tests: LeafFaceClient queryStatus
    // ============================================================

    public function test_query_status_rejects_empty_certify_id(): void
    {
        $client = new LeafFaceClient($this->defaultConfig());

        $result = $client->queryStatus('');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('已失效', $result['message']);
    }

    public function test_query_status_returns_completed(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-COMPLETED' => Http::response([
                'task' => ['task_no' => 'VT-COMPLETED', 'type' => 'h5_face', 'status' => 'completed'],
                'order' => ['status' => 'completed', 'matched' => true],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-COMPLETED');

        $this->assertSame(1, $result['status']);
        $this->assertSame('审核通过', $result['message']);
    }

    public function test_query_status_returns_failed(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-FAILED' => Http::response([
                'task' => ['task_no' => 'VT-FAILED', 'type' => 'h5_face', 'status' => 'failed'],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-FAILED');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('重新发起', $result['message']);
    }

    public function test_query_status_returns_pending_when_created(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-CREATED' => Http::response([
                'task' => ['task_no' => 'VT-CREATED', 'type' => 'h5_face', 'status' => 'created'],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-CREATED');

        $this->assertSame(4, $result['status']);
        $this->assertSame('等待用户完成认证', $result['message']);
    }

    public function test_query_status_returns_failed_when_expired(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-EXPIRED' => Http::response([
                'task' => ['task_no' => 'VT-EXPIRED', 'type' => 'h5_face', 'status' => 'expired'],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-EXPIRED');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('过期', $result['message']);
    }

    public function test_query_status_returns_failed_when_canceled(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-CANCELED' => Http::response([
                'task' => ['task_no' => 'VT-CANCELED', 'type' => 'h5_face', 'status' => 'canceled'],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-CANCELED');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('取消', $result['message']);
    }

    public function test_query_status_returns_pending_when_task_not_found(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-MISSING' => Http::response([
                'code' => 'TASK_NOT_FOUND',
                'message' => 'task not found',
            ], 404),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-MISSING');

        $this->assertSame(4, $result['status']);
    }

    public function test_query_status_returns_error_on_unknown_status(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-UNKNOWN' => Http::response([
                'task' => ['task_no' => 'VT-UNKNOWN', 'type' => 'h5_face', 'status' => 'weird'],
            ], 200),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-UNKNOWN');

        $this->assertSame(3, $result['status']);
    }

    public function test_query_status_returns_error_on_http_failure(): void
    {
        Http::fake([
            'face.ly-y.cn/api/merchant/verify/tasks/VT-HTTP-FAIL' => Http::response('not json', 500),
        ]);

        $client = new LeafFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-HTTP-FAIL');

        $this->assertSame(3, $result['status']);
        $this->assertStringContainsString('请求失败', $result['message']);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array
    {
        return [
            'app_id' => 'test-app-id',
            'app_secret' => 'test-app-secret',
            'api_base_url' => 'https://face.ly-y.cn',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackBody(string $taskNo): array
    {
        return [
            'task' => [
                'id' => '81a4e811-53dd-4302-aac1-a18c9a8e8583',
                'task_no' => $taskNo,
                'status' => 'completed',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{payload: array<string, mixed>, headers: array<string, string>, method: string, path: string, raw_body: string}
     */
    private function signCallback(array $body, ?string $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= gmdate('c');
        $nonce ??= bin2hex(random_bytes(16));
        $rawBody = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bodySha256 = hash('sha256', $rawBody);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$bodySha256, 'test-app-secret');

        return [
            'payload' => $body,
            'headers' => [
                'x-leafsm-timestamp' => $timestamp,
                'x-leafsm-nonce' => $nonce,
                'x-leafsm-signature' => $signature,
                'x-body-sha256' => $bodySha256,
                'x-leafsm-event' => 'verification.task.finished',
            ],
            'method' => 'POST',
            'path' => 'api/v2/client/verification/callback',
            'raw_body' => $rawBody,
        ];
    }

    private function seedVerifyUrlCache(): void
    {
        Cache::put(
            'leaf_face_verification:verify_url:'.hash('sha256', 'VT202607041130001234ABCD'),
            '/h5/face?task_id=81a4e811-53dd-4302-aac1-a18c9a8e8583',
            now()->addHour()
        );
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
