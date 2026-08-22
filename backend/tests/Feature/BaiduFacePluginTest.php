<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Certification\BaiduFace\Logic\BaiduFace;
use TuraIDC\Plugins\Certification\BaiduFace\Logic\BaiduFaceClient;

class BaiduFacePluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPluginTables();
        // 插件类走 PluginFileLoader 的 require_once 加载，不在 PSR-4 autoload 内，
        // 直接 new 之前必须先确保文件已载入。
        $this->loadBaiduFacePlugin();
    }

    private function loadBaiduFacePlugin(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'baidu_face');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    // ============================================================
    // Unit tests: BaiduFace execute() routing
    // ============================================================

    public function test_execute_routes_initialize_action(): void
    {
        $plugin = new BaiduFace;

        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate*' => Http::response([
                'success' => true, 'result' => ['verify_token' => 'VT-123'],
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/idcard/submit*' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = $plugin->execute([
            'action' => 'certification.initialize',
            'payload' => [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'cert_type' => 'IDENTITY_CARD',
                'return_url' => 'https://example.test/callback',
            ],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.initialize', $result['action']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('VT-123', $result['data']['certify_id']);
    }

    public function test_execute_routes_scan_url_action(): void
    {
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'action' => 'certification.scan_url',
            'payload' => ['certify_id' => 'VT-123'],
            'config' => $this->defaultConfig(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('certification.scan_url', $result['action']);
        $this->assertStringStartsWith('https://brain.baidu.com/face/print/', $result['data']['url']);
        $this->assertStringContainsString('token=VT-123', $result['data']['url']);
    }

    public function test_execute_routes_fee_config_action(): void
    {
        $plugin = new BaiduFace;

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
        $plugin = new BaiduFace;

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
        $plugin = new BaiduFace;

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
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'payload' => [],
            'config' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Unsupported plugin action', $result['message']);
    }

    // ============================================================
    // Unit tests: verifyCallback
    // ============================================================

    public function test_verify_callback_accepts_non_empty_certify_id(): void
    {
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => ['payload' => ['certify_id' => 'VT-123']],
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame(200, $result['data']['http_status']);
    }

    public function test_verify_callback_accepts_token_fallback(): void
    {
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => ['payload' => ['token' => 'VT-456']],
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
    }

    public function test_verify_callback_accepts_empty_certify_id_for_guidance(): void
    {
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => ['payload' => []],
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame(200, $result['data']['http_status']);
        $this->assertStringContainsString('前端页面', $result['data']['message']);
    }

    public function test_verify_callback_accepts_empty_payload_for_guidance(): void
    {
        $plugin = new BaiduFace;

        $result = $plugin->execute([
            'action' => 'certification.verify_callback',
            'payload' => [],
            'config' => [],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['passed']);
        $this->assertSame(200, $result['data']['http_status']);
    }

    // ============================================================
    // Unit tests: BaiduFaceClient initialize
    // ============================================================

    public function test_initialize_rejects_non_identity_card(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $client->initialize('张三', '110101199001010011', 'PASSPORT', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('仅支持大陆身份证', $result['message']);
    }

    public function test_initialize_returns_error_when_token_generation_fails(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate*' => Http::response([
                'success' => false, 'error_code' => 100, 'error_msg' => 'plan not found',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertSame('获取百度实名认证令牌失败，请联系管理员', $result['message']);
    }

    public function test_initialize_returns_error_when_submit_fails(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate*' => Http::response([
                'success' => true, 'result' => ['verify_token' => 'VT-FAIL'],
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/idcard/submit*' => Http::response([
                'success' => false, 'error_code' => 216100, 'error_msg' => '身份证号码格式错误',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->initialize('张三', 'bad-id', 'IDENTITY_CARD', '');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('身份证号码格式错误', $result['message']);
    }

    public function test_initialize_throws_when_h5_plan_id_missing(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('百度 H5 方案ID未配置');

        $client = new BaiduFaceClient([
            'api_key' => 'key', 'secret_key' => 'secret',
            'h5_plan_id' => 0,
        ]);
        $client->initialize('张三', '110101199001010011', 'IDENTITY_CARD', '');
    }

    // ============================================================
    // Unit tests: BaiduFaceClient generateScanUrl
    // ============================================================

    public function test_generate_scan_url_rejects_empty_certify_id(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $client->generateScanUrl('');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('已失效', $result['message']);
    }

    public function test_generate_scan_url_includes_callback_when_cached(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());
        $cacheKey = $this->invokeClientMethod($client, 'returnUrlCacheKey', ['VT-URL']);
        Cache::put($cacheKey, 'https://example.test/cb?certify_id=VT-URL', now()->addMinute());

        $result = $client->generateScanUrl('VT-URL');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('successUrl=', $result['url']);
        $this->assertStringContainsString('failedUrl=', $result['url']);
    }

    // ============================================================
    // Unit tests: queryStatus edge cases
    // ============================================================

    public function test_query_status_rejects_empty_certify_id(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $client->queryStatus('');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('已失效', $result['message']);
    }

    public function test_query_status_returns_pending_on_qps_limit(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => false, 'error_code' => 18, 'error_msg' => 'QPS limit',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-PENDING');

        $this->assertSame(4, $result['status']);
        $this->assertSame('认证处理中，请稍后再试', $result['message']);
    }

    public function test_query_status_returns_pending_for_216402(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => false, 'error_code' => 216402, 'error_msg' => 'no result',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-216402');

        $this->assertSame(4, $result['status']);
    }

    public function test_query_status_returns_pending_on_chinese_keyword(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => false, 'error_code' => 999, 'error_msg' => '认证处理中，请等待',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-CHINESE');

        $this->assertSame(4, $result['status']);
    }

    public function test_query_status_passes_on_verify_status_success(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 'SUCCESS', 'verify_user_msg' => '审核通过'],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-SUCCESS');

        $this->assertSame(1, $result['status']);
        $this->assertSame('审核通过', $result['message']);
    }

    public function test_query_status_returns_pending_on_audit_status(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 'AUDIT_PROCESSING'],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-AUDIT');

        $this->assertSame(4, $result['status']);
        $this->assertSame('认证处理中，请稍后再试', $result['message']);
    }

    public function test_query_status_returns_failed_on_verify_status_failed(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 'FAILED', 'verify_user_msg' => '认证失败'],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-FAILED');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('认证失败', $result['message']);
    }

    public function test_query_status_passes_on_verify_result_structure(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true,
                'result' => [
                    'verify_result' => [
                        'verify_log_id' => '1119154752105640727',
                        'score' => 98.35,
                        'liveness_score' => 0.99999726,
                    ],
                    'idcard_confirm' => ['idcard_number' => '610723200804157111', 'name' => '李维佳'],
                ],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-VERIFY-RESULT');

        $this->assertSame(1, $result['status']);
        $this->assertSame('审核通过', $result['message']);
    }

    public function test_query_status_returns_failed(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => false, 'error_code' => 222351, 'error_msg' => '人脸与身份证不符',
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-FAIL');

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('人脸与身份证不符', $result['message']);
    }

    public function test_query_status_returns_error_on_http_failure(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response('not json', 500),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-HTTP-FAIL');

        $this->assertSame(3, $result['status']);
        $this->assertStringContainsString('请求失败', $result['message']);
    }

    // ============================================================
    // Unit tests: directVerify
    // ============================================================

    public function test_direct_verify_rejects_missing_fields(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $client->directVerify([]);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('缺少', $result['message']);
    }

    public function test_direct_verify_v4_passes(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rest/2.0/face/v4/mingjing/verify*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 0, 'score' => 95.5],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->directVerify([
            'image' => 'base64data',
            'real_name' => '张三',
            'id_card' => '110101199001010011',
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('审核通过', $result['message']);
    }

    public function test_direct_verify_v4_accepts_provider_success_without_score_threshold(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rest/2.0/face/v4/mingjing/verify*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 0, 'score' => 60.0],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->directVerify([
            'image' => 'base64data',
            'real_name' => '张三',
            'id_card' => '110101199001010011',
        ]);

        $this->assertSame(200, $result['status']);
    }

    public function test_direct_verify_v3_passes(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rest/2.0/face/v3/person/verify*' => Http::response([
                'success' => true,
                'result' => ['verify_status' => 0, 'score' => 90.0],
            ], 200),
        ]);

        $client = new BaiduFaceClient(array_merge($this->defaultConfig(), ['api_version' => 'v3']));
        $result = $client->directVerify([
            'image' => 'base64data',
            'real_name' => '张三',
            'id_card' => '110101199001010011',
        ]);

        $this->assertSame(200, $result['status']);
    }

    // ============================================================
    // Unit tests: isBaiduSuccess (via reflection)
    // ============================================================

    public function test_is_baidu_success_returns_true_for_success_true(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isBaiduSuccess', [['success' => true]]));
    }

    public function test_is_baidu_success_returns_true_for_code_zero(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isBaiduSuccess', [['error_code' => 0]]));
        $this->assertTrue($this->invokeClientMethod($client, 'isBaiduSuccess', [['code' => '0']]));
    }

    public function test_is_baidu_success_returns_false_for_failed_message(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isBaiduSuccess', [[
            'error_code' => 0, 'error_msg' => 'FAILED',
        ]]));
        $this->assertFalse($this->invokeClientMethod($client, 'isBaiduSuccess', [[
            'code' => 0, 'message' => 'FAIL',
        ]]));
    }

    public function test_is_baidu_success_correctly_rejects_empty_array(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        // Empty array: no error_code/code present, no success key → should reject
        $this->assertFalse($this->invokeClientMethod($client, 'isBaiduSuccess', [[]]));
    }

    public function test_is_baidu_success_rejects_array_without_code_or_success(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isBaiduSuccess', [['message' => 'some message']]));
    }

    // ============================================================
    // Unit tests: isPendingResult
    // ============================================================

    public function test_is_pending_result_for_pending_codes(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '18']]));
        $this->assertTrue($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '216402']]));
        $this->assertTrue($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '216403']]));
    }

    public function test_is_pending_result_for_pending_keywords(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '999', 'error_msg' => '未查询到结果']]));
        $this->assertTrue($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '999', 'error_msg' => 'not found']]));
    }

    public function test_is_not_pending_result_for_normal_error(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isPendingResult', [['error_code' => '222351', 'error_msg' => '人脸不匹配']]));
    }

    // ============================================================
    // Unit tests: safeProviderMessage
    // ============================================================

    public function test_safe_provider_message_returns_chinese_text(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'safeProviderMessage', [['error_msg' => '身份证号码格式错误'], 'fallback']);

        $this->assertSame('身份证号码格式错误', $result);
    }

    public function test_safe_provider_message_falls_back_for_technical_error(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'safeProviderMessage', [['error_msg' => 'Connection timeout'], '请核对后重试']);

        $this->assertSame('请核对后重试', $result);
    }

    public function test_safe_provider_message_falls_back_for_empty_message(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'safeProviderMessage', [[], '请核对后重试']);

        $this->assertSame('请核对后重试', $result);
    }

    // ============================================================
    // Integration test: config save clears cache
    // ============================================================

    public function test_config_save_clears_access_token_cache(): void
    {
        $this->ensurePluginTables();

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);
        $manifest = $scanner->requireManifest('verification', 'baidu_face');
        $plugin = $installer->install('verification', 'baidu_face');

        $newConfig = [
            'api_key' => 'new-key',
            'secret_key' => 'new-secret',
            'h5_plan_id' => 25921,
        ];
        $cacheKey = BaiduFaceClient::accessTokenCacheKeyForConfig($newConfig);
        $this->assertIsString($cacheKey);

        Cache::put($cacheKey, 'cached-token', now()->addHour());
        $this->assertTrue(Cache::has($cacheKey));

        $configRepository->save($plugin, $manifest, $newConfig);

        $this->assertFalse(Cache::has($cacheKey));
    }

    // ============================================================
    // Unit tests: accessToken caching
    // ============================================================

    public function test_access_token_is_cached_and_reused(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'fresh-token', 'expires_in' => 7200,
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());

        // First call triggers HTTP request
        $token1 = $this->invokeClientMethod($client, 'accessToken');
        $this->assertSame('fresh-token', $token1);

        // Ensure cache is populated by checking the cache directly
        $cacheKey = BaiduFaceClient::accessTokenCacheKeyForConfig($this->defaultConfig());
        $this->assertTrue(Cache::has($cacheKey));

        Http::assertSentCount(1);
    }

    // ============================================================
    // Unit tests: stringFromPaths
    // ============================================================

    public function test_string_from_paths_returns_first_match(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'stringFromPaths', [[
            'result' => ['token' => 'abc'],
            'data' => ['token' => 'def'],
        ], [
            ['result', 'token'],
            ['data', 'token'],
            ['token'],
        ]]);

        $this->assertSame('abc', $result);
    }

    public function test_string_from_paths_falls_back_to_next_path(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'stringFromPaths', [[
            'data' => ['token' => 'def'],
        ], [
            ['result', 'token'],
            ['data', 'token'],
            ['token'],
        ]]);

        $this->assertSame('def', $result);
    }

    public function test_string_from_paths_returns_empty_when_no_match(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'stringFromPaths', [[], [['token']]]);

        $this->assertSame('', $result);
    }

    // ============================================================
    // Unit tests: isDirectVerificationPassed
    // ============================================================

    public function test_direct_verification_passed_depends_on_provider_verify_status(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isDirectVerificationPassed', [[
            'success' => true, 'result' => ['verify_status' => 0, 'score' => 0],
        ]]));
        $this->assertFalse($this->invokeClientMethod($client, 'isDirectVerificationPassed', [[
            'success' => true, 'result' => ['score' => 100],
        ]]));
    }

    public function test_direct_verification_fails_on_nonzero_verify_status(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isDirectVerificationPassed', [[
            'success' => true, 'result' => ['verify_status' => 1, 'score' => 90.0],
        ]]));
    }

    // ============================================================
    // Unit tests: apiVersion
    // ============================================================

    public function test_api_version_defaults_to_v4(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertSame('v4', $this->invokeClientMethod($client, 'apiVersion'));
    }

    public function test_api_version_respects_v3_config(): void
    {
        $client = new BaiduFaceClient(array_merge($this->defaultConfig(), ['api_version' => 'v3']));

        $this->assertSame('v3', $this->invokeClientMethod($client, 'apiVersion'));
    }

    public function test_api_version_falls_back_to_v4_for_unknown(): void
    {
        $client = new BaiduFaceClient(array_merge($this->defaultConfig(), ['api_version' => 'v2']));

        $this->assertSame('v4', $this->invokeClientMethod($client, 'apiVersion'));
    }

    // ============================================================
    // Unit tests: endpoint resolution
    // ============================================================

    public function test_endpoint_uses_configured_value(): void
    {
        $client = new BaiduFaceClient(array_merge($this->defaultConfig(), [
            'h5_entry_url' => 'https://custom.test/face/',
        ]));

        $result = $this->invokeClientMethod($client, 'endpoint', ['h5_entry_url', 'https://default.test/']);

        $this->assertSame('https://custom.test/face/', $result);
    }

    public function test_endpoint_falls_back_to_default(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'endpoint', ['nonexistent_key', 'https://default.test/']);

        $this->assertSame('https://default.test/', $result);
    }

    // ============================================================
    // Unit tests: resolveCertificateType
    // ============================================================

    public function test_resolve_certificate_type_normalizes(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertSame('IDENTITY_CARD', $this->invokeClientMethod($client, 'resolveCertificateType', ['identity_card']));
        $this->assertSame('PASSPORT', $this->invokeClientMethod($client, 'resolveCertificateType', ['passport']));
        $this->assertSame('IDENTITY_CARD', $this->invokeClientMethod($client, 'resolveCertificateType', ['']));
    }

    // ============================================================
    // Unit tests: callbackUrl construction
    // ============================================================

    public function test_callback_url_appends_certify_id(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'callbackUrl', ['https://example.test/cb', 'VT-123']);

        $this->assertStringContainsString('certify_id=VT-123', $result);
    }

    public function test_callback_url_handles_existing_query(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'callbackUrl', ['https://example.test/cb?foo=bar', 'VT-123']);

        $this->assertStringContainsString('&certify_id=VT-123', $result);
    }

    public function test_callback_url_returns_empty_for_empty_return_url(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'callbackUrl', ['', 'VT-123']);

        $this->assertSame('', $result);
    }

    public function test_callback_url_without_certify_id_returns_original_url(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $result = $this->invokeClientMethod($client, 'callbackUrl', ['https://example.test/cb', null]);

        $this->assertSame('https://example.test/cb', $result);
    }

    // ============================================================
    // Unit tests: accessToken error handling
    // ============================================================

    public function test_access_token_throws_when_api_key_missing(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('未配置');

        $client = new BaiduFaceClient(['api_key' => '', 'secret_key' => 'secret']);
        $this->invokeClientMethod($client, 'accessToken');
    }

    public function test_access_token_throws_when_secret_key_missing(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('未配置');

        $client = new BaiduFaceClient(['api_key' => 'key', 'secret_key' => '']);
        $this->invokeClientMethod($client, 'accessToken');
    }

    public function test_access_token_throws_on_non_json_response(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('返回异常');

        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response('not json', 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $this->invokeClientMethod($client, 'accessToken');
    }

    public function test_access_token_throws_on_missing_token_in_response(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('配置错误');

        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response(['error' => 'invalid_client'], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $this->invokeClientMethod($client, 'accessToken');
    }

    // ============================================================
    // Unit tests: isVerificationPassed
    // ============================================================

    public function test_is_verification_not_passed_without_conclusion_field(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        // 顶层 success 只代表接口调用成功，缺少 result 内的结论字段时不得判定为通过。
        $this->assertFalse($this->invokeClientMethod($client, 'isVerificationPassed', [['success' => true]]));
    }

    public function test_is_verification_not_passed_when_result_status_failed(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['status' => 'failed'],
        ]]));
        $this->assertFalse($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['status' => 'rejected'],
        ]]));
    }

    public function test_is_verification_passed_with_numeric_verify_status(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        // verify_status 为数值语义：0 通过，非 0 不通过。
        $this->assertTrue($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['verify_status' => 0],
        ]]));
        $this->assertFalse($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['verify_status' => 1],
        ]]));
    }

    public function test_query_status_returns_pending_when_conclusion_missing(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true, 'log_id' => 123456,
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-NO-CONCLUSION');

        // 接口通了但没有结论字段：应为"处理中"，不能误判为通过或失败。
        $this->assertSame(4, $result['status']);
    }

    public function test_query_status_does_not_pass_on_failed_result_status(): void
    {
        Http::fake([
            'aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'token', 'expires_in' => 7200,
            ], 200),
            'aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true, 'result' => ['status' => 'failed'],
            ], 200),
        ]);

        $client = new BaiduFaceClient($this->defaultConfig());
        $result = $client->queryStatus('VT-BIZ-FAIL');

        $this->assertSame(2, $result['status']);
    }

    public function test_is_verification_passed_with_status_success(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['status' => 'success'],
        ]]));
    }

    public function test_is_verification_passed_with_status_passed(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertTrue($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => true, 'result' => ['status' => 'PASSED'],
        ]]));
    }

    public function test_is_verification_not_passed_with_unknown_status(): void
    {
        $client = new BaiduFaceClient($this->defaultConfig());

        $this->assertFalse($this->invokeClientMethod($client, 'isVerificationPassed', [[
            'success' => false, 'error_code' => 222351, 'error_msg' => '不匹配',
        ]]));
    }

    // ============================================================
    // Manifest: 计费配置项必须在 schema 中声明才能落库
    // ============================================================

    public function test_manifest_declares_billing_config_keys(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'baidu_face');
        $keys = array_map(
            static fn (array $item): string => (string) ($item['key'] ?? ''),
            $manifest->configSchema
        );

        // PluginConfigRepository 按 schema 遍历入库，未声明的键无法保存。
        foreach (['charge_enabled', 'amount', 'free_times'] as $key) {
            $this->assertContains($key, $keys, "配置项 [{$key}] 缺失将导致对应功能无法配置");
        }

        $this->assertContains('fee_config', $manifest->capabilities);
        $this->assertContains('verify_callback', $manifest->capabilities);
    }

    // ============================================================
    // Helper methods
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array
    {
        return [
            'api_key' => 'test-api-key',
            'secret_key' => 'test-secret-key',
            'api_version' => 'v4',
            'h5_plan_id' => 25921,
        ];
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invokeClientMethod(BaiduFaceClient $client, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($client, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($client, ...$args);
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
