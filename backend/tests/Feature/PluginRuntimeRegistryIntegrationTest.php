<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentGatewayCode;
use App\Models\IntegrationPlugin;
use App\Models\Setting;
use App\Services\Auth\GeeTestService;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Integrations\Plugins\Adapters\PluginPaymentGateway;
use App\Services\Integrations\Plugins\Adapters\PluginSmsDriver;
use App\Services\Integrations\Plugins\Adapters\PluginUpstreamDriver;
use App\Services\Integrations\Plugins\Adapters\PluginVerificationDriver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Sms\SmsDriverManager;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Verification\Contracts\ProvidesVerificationFeeConfig;
use App\Services\Verification\Contracts\VerifiesVerificationCallbacks;
use App\Services\Verification\Data\VerificationCallbackRequest;
use App\Services\Verification\VerificationDriverManager;
use TuraIDC\Plugins\Captcha\Geetest\GeetestPlugin;
use TuraIDC\Plugins\Captcha\Geetest\Lib\GeetestCaptchaService;
use TuraIDC\Plugins\Captcha\Vaptcha\Lib\VaptchaCaptchaService;
use TuraIDC\Plugins\Captcha\Vaptcha\VaptchaPlugin;
use TuraIDC\Plugins\Certification\BaiduFace\Logic\BaiduFaceClient;
use TuraIDC\Plugins\Certification\DemoVerification\DemoVerificationPlugin;
use TuraIDC\Plugins\Certification\Stay33\Logic\Stay33;
use TuraIDC\Plugins\Certification\Stay33\Logic\Stay33Client;
use TuraIDC\Plugins\Certification\Stay33\Stay33Plugin;
use TuraIDC\Plugins\Gateways\AliPay\AliPayPlugin;
use TuraIDC\Plugins\Gateways\AliPay\Controller\IndexController;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceAdapter;
use TuraIDC\Plugins\Servers\ZjmfFinance\Logic\ZjmfFinance;
use TuraIDC\Plugins\Servers\ZjmfFinance\ZjmfFinancePlugin;
use TuraIDC\Plugins\Sms\Aliyun\AliyunPlugin;
use TuraIDC\Plugins\Sms\Aliyun\Lib\AliyunSmsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PluginRuntimeRegistryIntegrationTest extends TestCase
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

    public function test_runtime_normalizes_non_boolean_success_returned_by_plugin(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'demo_verification', [
            'api_url' => 'https://example.test',
            'app_id' => 'demo-app',
            'app_secret' => 'demo-secret',
        ]);

        // 插件返回字符串 'false'：不做归一化的话 array_merge 会把它原样透出，
        // 下游 `=== false` 判不出失败，等于把失败当成功放行。
        $this->app->bind(DemoVerificationPlugin::class, fn (): object => new class
        {
            /**
             * @param  array<string, mixed>  $request
             * @return array<string, mixed>
             */
            public function execute(array $request): array
            {
                return [
                    'success' => 'false',
                    'action' => '伪造的动作',
                    'message' => ['不是字符串'],
                    'data' => ['verified' => false],
                ];
            }
        });

        $result = app(PluginRuntimeRegistry::class)->execute(
            domain: 'verification',
            slugOrKey: 'demo_verification',
            action: 'certification.initialize',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('certification.initialize', $result['action']);
        $this->assertSame('', $result['message']);
        $this->assertSame('demo_verification', $result['plugin']['slug']);
    }

    public function test_runtime_executes_enabled_plugin_through_standard_execute_entry(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'demo_verification', [
            'api_url' => 'https://example.test',
            'app_id' => 'demo-app',
            'app_secret' => 'demo-secret',
        ]);

        $result = app(PluginRuntimeRegistry::class)->execute(
            domain: 'verification',
            slugOrKey: 'demo_verification',
            action: 'certification.initialize',
            payload: [
                'real_name' => 'Test User',
                'id_card' => '110101199001010011',
                'cert_type' => 'FACE',
                'return_url' => 'https://example.test/return',
            ],
            context: ['trace_id' => 'plugin-runtime-test'],
        );

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('certification.initialize', $result['action'] ?? null);
        $this->assertSame('demo_verification', $result['plugin']['key'] ?? null);
        $this->assertSame(
            'demo-certify-'.sha1('Test User110101199001010011'),
            $result['data']['certify_id'] ?? null,
        );
    }

    public function test_non_verification_plugin_manifests_do_not_expose_ssl_configuration(): void
    {
        $scanner = app(PluginScanner::class);

        $alipayConfigKeys = collect($scanner->requireManifest('payment', 'ali_pay')->configSchema)
            ->pluck('key')
            ->all();
        $yipayConfigKeys = collect($scanner->requireManifest('payment', 'yi_pay')->configSchema)
            ->pluck('key')
            ->all();
        $geetestConfigKeys = collect($scanner->requireManifest('captcha', 'geetest')->configSchema)
            ->pluck('key')
            ->all();
        $vaptchaConfigKeys = collect($scanner->requireManifest('captcha', 'vaptcha')->configSchema)
            ->pluck('key')
            ->all();
        $aliyunConfigKeys = collect($scanner->requireManifest('sms', 'aliyun')->configSchema)
            ->pluck('key')
            ->all();

        $this->assertNotContains('template_code', $aliyunConfigKeys);
        $this->assertNotContains('api_endpoint', $aliyunConfigKeys);
        $this->assertNotContains('message_api_endpoint', $aliyunConfigKeys);
        $aliyunSignName = collect($scanner->requireManifest('sms', 'aliyun')->configSchema)
            ->firstWhere('key', 'sign_name');
        $this->assertSame('text', $aliyunSignName['type'] ?? null);
        $this->assertNull($aliyunSignName['options'] ?? null);
        $this->assertArrayNotHasKey('actions', $aliyunSignName);

        $this->assertNotContains('gateway', $alipayConfigKeys);
        $this->assertNotContains('notify_url', $alipayConfigKeys);
        $this->assertNotContains('api_base_url', $yipayConfigKeys);
        $this->assertNotContains('notify_url', $yipayConfigKeys);
        $this->assertSame(['basic_notice', 'vid', 'vkey'], $vaptchaConfigKeys);

        foreach ([$alipayConfigKeys, $yipayConfigKeys, $geetestConfigKeys, $vaptchaConfigKeys, $aliyunConfigKeys] as $configKeys) {
            $this->assertNotContains('ssl_verify', $configKeys);
            $this->assertNotContains('ca_bundle', $configKeys);
        }
    }

    public function test_stay33_verification_plugin_exposes_ssl_configuration(): void
    {
        $configKeys = collect(app(PluginScanner::class)->requireManifest('verification', 'stay33')->configSchema)
            ->pluck('key')
            ->all();

        $this->assertContains('ssl_verify', $configKeys);
        $this->assertContains('ca_bundle', $configKeys);
    }

    public function test_baidu_face_verification_plugin_exposes_required_h5_configuration(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('verification', 'baidu_face');
        $schema = collect($manifest->configSchema)->keyBy('key');

        $this->assertSame('baidu_face', $manifest->key);
        $this->assertContains('scan_url', $manifest->capabilities);
        $this->assertContains('query_status', $manifest->capabilities);
        $this->assertContains('direct_verify', $manifest->capabilities);
        $this->assertTrue((bool) ($schema['api_key']['secret'] ?? false));
        $this->assertTrue((bool) ($schema['secret_key']['secret'] ?? false));
        $this->assertSame('v4', $schema['api_version']['default'] ?? null);
        $this->assertSame(25921, $schema['h5_plan_id']['default'] ?? null);
        $this->assertSame([
            'basic_notice',
            'api_key',
            'secret_key',
            'api_version',
            'h5_plan_id',
            'billing_divider',
            'charge_enabled',
            'amount',
            'free_times',
        ], $schema->keys()->all());
    }

    public function test_stay33_client_prefers_plugin_ssl_configuration_before_legacy_config(): void
    {
        $legacyCaBundle = tempnam(sys_get_temp_dir(), 'stay33-legacy-ca-');
        $pluginCaBundle = tempnam(sys_get_temp_dir(), 'stay33-plugin-ca-');
        $this->assertIsString($legacyCaBundle);
        $this->assertIsString($pluginCaBundle);

        try {
            config([
                'idc.verification.ssl_verify' => false,
                'idc.verification.ca_bundle' => $legacyCaBundle,
            ]);

            $manifest = app(PluginScanner::class)->requireManifest('verification', 'stay33');
            app(PluginFileLoader::class)->ensureLoaded($manifest);

            $pluginConfiguredClient = new Stay33Client([
                'ssl_verify' => true,
                'ca_bundle' => $pluginCaBundle,
            ]);

            $this->assertTrue($this->invokeStay33ClientMethod($pluginConfiguredClient, 'resolveSslVerify'));
            $this->assertSame($pluginCaBundle, $this->invokeStay33ClientMethod($pluginConfiguredClient, 'resolveCaBundle'));

            $legacyConfiguredClient = new Stay33Client([]);

            $this->assertFalse($this->invokeStay33ClientMethod($legacyConfiguredClient, 'resolveSslVerify'));
            $this->assertSame($legacyCaBundle, $this->invokeStay33ClientMethod($legacyConfiguredClient, 'resolveCaBundle'));
        } finally {
            @unlink($legacyCaBundle);
            @unlink($pluginCaBundle);
        }
    }

    public function test_baidu_face_h5_flow_initializes_link_and_queries_success_status(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'baidu_face', [
            'api_key' => 'baidu-api-key',
            'secret_key' => 'baidu-secret-key',
            'api_version' => 'v4',
            'h5_plan_id' => 25921,
        ]);

        Http::fake([
            'https://aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'baidu-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate*' => Http::response([
                'success' => true,
                'result' => ['verify_token' => 'BAIDU-VERIFY-TOKEN'],
            ], 200),
            'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/idcard/submit*' => Http::response(['success' => true], 200),
            'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => true,
                'result' => ['status' => 'success'],
            ], 200),
        ]);

        $runtime = app(PluginRuntimeRegistry::class);
        $initialize = $runtime->execute(
            domain: 'verification',
            slugOrKey: 'baidu_face',
            action: 'certification.initialize',
            payload: [
                'real_name' => '张三',
                'id_card' => '110101199001010011',
                'cert_type' => 'IDENTITY_CARD',
                'return_url' => 'https://backend.example.test/api/v2/client/verification/callback',
            ],
        );

        $this->assertSame(200, $initialize['data']['status'] ?? null);
        $this->assertSame('BAIDU-VERIFY-TOKEN', $initialize['data']['certify_id'] ?? null);

        $scanUrl = $runtime->execute(
            domain: 'verification',
            slugOrKey: 'baidu_face',
            action: 'certification.scan_url',
            payload: ['certify_id' => 'BAIDU-VERIFY-TOKEN'],
        );

        $this->assertSame(200, $scanUrl['data']['status'] ?? null);
        $this->assertStringStartsWith('https://brain.baidu.com/face/print/?', $scanUrl['data']['url'] ?? '');
        $this->assertStringContainsString('token=BAIDU-VERIFY-TOKEN', $scanUrl['data']['url'] ?? '');
        $this->assertStringContainsString('successUrl=', $scanUrl['data']['url'] ?? '');

        $query = $runtime->execute(
            domain: 'verification',
            slugOrKey: 'baidu_face',
            action: 'certification.query_status',
            payload: ['certify_id' => 'BAIDU-VERIFY-TOKEN'],
        );

        $this->assertSame(1, $query['data']['status'] ?? null);
        $this->assertSame('审核通过', $query['data']['message'] ?? null);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/verifyToken/generate?')
            && $request['plan_id'] === 25921
            && is_array($request['redirect_config'] ?? null)
            && str_contains((string) ($request['redirect_config']['success_url'] ?? ''), 'certify_id=BAIDU-VERIFY-TOKEN') === false);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/idcard/submit?')
            && $request['verify_token'] === 'BAIDU-VERIFY-TOKEN'
            && $request['id_name'] === '张三'
            && $request['id_no'] === '110101199001010011'
            && $request['certificate_type'] === 0);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail?')
            && $request['verify_token'] === 'BAIDU-VERIFY-TOKEN');
    }

    public function test_baidu_face_query_status_maps_not_ready_result_to_pending(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'baidu_face', [
            'api_key' => 'baidu-api-key',
            'secret_key' => 'baidu-secret-key',
            'h5_plan_id' => 25921,
        ]);

        Http::fake([
            'https://aip.baidubce.com/oauth/2.0/token*' => Http::response([
                'access_token' => 'baidu-access-token',
                'expires_in' => 7200,
            ], 200),
            'https://aip.baidubce.com/rpc/2.0/brain/solution/faceprint/result/detail*' => Http::response([
                'success' => false,
                'error_code' => 18,
                'error_msg' => 'Open api qps request limit reached',
            ], 200),
        ]);

        $query = app(PluginRuntimeRegistry::class)->execute(
            domain: 'verification',
            slugOrKey: 'baidu_face',
            action: 'certification.query_status',
            payload: ['certify_id' => 'BAIDU-PENDING-TOKEN'],
        );

        $this->assertSame(4, $query['data']['status'] ?? null);
        $this->assertSame('认证处理中，请稍后再试', $query['data']['message'] ?? null);
    }

    public function test_baidu_face_config_save_clears_previous_and_current_access_token_cache(): void
    {
        $this->ensurePluginTables();

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);
        $manifest = $scanner->requireManifest('verification', 'baidu_face');
        $plugin = $installer->install('verification', 'baidu_face');
        $previousConfig = [
            'api_key' => 'old-api-key',
            'secret_key' => 'old-secret-key',
            'h5_plan_id' => 25921,
        ];
        $currentConfig = [
            'api_key' => 'new-api-key',
            'secret_key' => 'new-secret-key',
            'h5_plan_id' => 25921,
        ];

        $configRepository->save($plugin, $manifest, $previousConfig);

        $previousCacheKey = BaiduFaceClient::accessTokenCacheKeyForConfig($previousConfig);
        $currentCacheKey = BaiduFaceClient::accessTokenCacheKeyForConfig($currentConfig);
        $this->assertIsString($previousCacheKey);
        $this->assertIsString($currentCacheKey);

        Cache::put($previousCacheKey, 'old-access-token', now()->addHour());
        Cache::put($currentCacheKey, 'new-access-token', now()->addHour());

        $configRepository->save($plugin->fresh() ?? $plugin, $manifest, $currentConfig);

        $this->assertFalse(Cache::has($previousCacheKey));
        $this->assertFalse(Cache::has($currentCacheKey));
    }

    public function test_geetest_captcha_plugin_drives_auth_captcha_service(): void
    {
        $this->ensurePluginTables();

        $inactiveService = new GeeTestService(app(PluginRuntimeRegistry::class));
        $this->assertFalse($inactiveService->isEnabled());

        Setting::setValues('system', [
            'captcha_enabled' => '0',
        ]);

        $this->activatePlugin('captcha', 'geetest', [
            'captcha_id' => 'captcha-id',
            'captcha_key' => 'captcha-key',
        ]);

        Http::fake([
            'https://gcaptcha4.geetest.com/validate' => Http::response(['result' => 'success'], 200),
        ]);

        $service = new GeeTestService(app(PluginRuntimeRegistry::class));

        $this->assertTrue($service->isEnabled());
        $this->assertSame('captcha-id', $service->getCaptchaId());
        $this->assertSame('/api/v2/client/auth/captcha-script', $service->getScriptUrl());

        $result = $service->verify([
            'lot_number' => 'lot-number',
            'captcha_output' => 'captcha-output',
            'pass_token' => 'pass-token',
            'gen_time' => '1234567890',
        ]);

        $this->assertSame(['ok' => true], $result);
        $this->assertDatabaseHas('integration_plugin_bindings', [
            'domain' => 'captcha',
            'binding_key' => 'captcha_driver',
            'provider_key' => 'geetest',
            'status' => 1,
        ]);
        $this->assertTrue(class_exists(GeetestPlugin::class));
        $this->assertTrue(class_exists(GeetestCaptchaService::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gcaptcha4.geetest.com/validate'
            && $request['captcha_id'] === 'captcha-id'
            && $request['sign_token'] === hash_hmac('sha256', 'lot-number', 'captcha-key'));
    }

    public function test_enabled_captcha_plugin_drives_auth_captcha_service_without_driver_binding(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('captcha', 'geetest', [
            'captcha_id' => 'captcha-id',
            'captcha_key' => 'captcha-key',
        ]);

        DB::table('integration_plugin_bindings')
            ->where('domain', 'captcha')
            ->delete();

        $service = new GeeTestService(app(PluginRuntimeRegistry::class));

        $this->assertTrue($service->isEnabled());
        $this->assertSame('captcha-id', $service->getCaptchaId());
    }

    public function test_vaptcha_captcha_plugin_drives_auth_captcha_service(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('captcha', 'vaptcha', [
            'vid' => 'vaptcha-vid',
            'vkey' => 'vaptcha-secret',
        ]);

        Http::fake([
            'https://v4i.vaptcha.com/api/v1/verify' => Http::response([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'result' => true,
                    'vid' => 'vaptcha-vid',
                    'status' => 'success',
                ],
            ], 200),
        ]);

        $service = new GeeTestService(app(PluginRuntimeRegistry::class));

        $this->assertTrue($service->isEnabled());
        $this->assertSame('vaptcha-vid', $service->getCaptchaId());
        $scriptContent = $service->getScriptContent();
        $this->assertStringContainsString('https://cdn4.vaptcha.com/src/v4.js', $scriptContent);
        $this->assertStringContainsString('https://cdn4.vaptcha.com/src/verify.html', $scriptContent);
        $this->assertStringContainsString('patchVerifyPageUrl', $scriptContent);
        $this->assertStringContainsString('global.initGeetest4', $scriptContent);

        $result = $service->verify([
            'token' => 'vaptcha-token',
            'knock' => 'vaptcha-knock',
            'dfu' => 'vaptcha-dfu',
        ], '203.0.113.9');

        $this->assertSame(['ok' => true], $result);

        $replayedResult = $service->verify([
            'token' => 'vaptcha-token',
            'knock' => 'vaptcha-knock',
            'dfu' => 'vaptcha-dfu',
        ], '203.0.113.9');

        $this->assertSame([
            'ok' => false,
            'message' => '行为验证已使用，请重新验证',
        ], $replayedResult);
        $this->assertDatabaseHas('integration_plugin_bindings', [
            'domain' => 'captcha',
            'binding_key' => 'captcha_driver',
            'provider_key' => 'vaptcha',
            'status' => 1,
        ]);
        $this->assertTrue(class_exists(VaptchaPlugin::class));
        $this->assertTrue(class_exists(VaptchaCaptchaService::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://v4i.vaptcha.com/api/v1/verify'
            && $request['vid'] === 'vaptcha-vid'
            && $request['vkey'] === 'vaptcha-secret'
            && $request['token'] === 'vaptcha-token'
            && $request['knock'] === 'vaptcha-knock'
            && $request['dfu'] === 'vaptcha-dfu'
            && $request['ip'] === '203.0.113.9');
        Http::assertSentCount(1);
    }

    public function test_payment_manager_prefers_enabled_payment_plugin_without_duplicate_registration(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('payment', 'ali_pay', [
            'alipay_enabled' => true,
            'app_id' => 'app-id',
            'private_key' => 'private-key',
            'alipay_public_key' => 'public-key',
        ]);

        $this->app->forgetInstance(PaymentGatewayRegistry::class);
        $this->app->forgetInstance(PaymentGatewayManager::class);

        $gateway = app(PaymentGatewayManager::class)->gateway(PaymentGatewayCode::ALIPAY);

        $this->assertSame(PluginPaymentGateway::class, $gateway::class);
        $this->assertTrue(class_exists(AliPayPlugin::class));
        $this->assertTrue(class_exists(IndexController::class));
        $this->assertSame([PaymentGatewayCode::ALIPAY], app(PaymentGatewayRegistry::class)->keys());
    }

    public function test_verification_manager_prefers_enabled_verification_plugin_without_duplicate_registration(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'stay33', [
            'api' => 'verification-api',
            'key' => 'verification-secret',
            'biz_code' => 'FACE',
        ]);

        $this->app->forgetInstance(VerificationDriverManager::class);

        $driver = app(VerificationDriverManager::class)->resolve('stay33');

        $this->assertSame(PluginVerificationDriver::class, $driver::class);
        $this->assertTrue(class_exists(Stay33Plugin::class));
        $this->assertTrue(class_exists(Stay33::class));
        $this->assertContains(
            ['value' => 'stay33', 'label' => $driver->label()],
            app(VerificationDriverManager::class)->options(),
        );
    }

    public function test_stay33_verification_plugin_owns_callback_verification_and_fee_config(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('verification', 'stay33', [
            'api' => 'verification-api',
            'key' => 'callback-secret',
            'biz_code' => 'FACE',
            'charge_enabled' => true,
            'amount' => 8.5,
            'free_times' => 5,
        ]);

        $this->app->forgetInstance(VerificationDriverManager::class);

        $driver = app(VerificationDriverManager::class)->resolve('stay33');
        $this->assertInstanceOf(VerifiesVerificationCallbacks::class, $driver);
        $this->assertInstanceOf(ProvidesVerificationFeeConfig::class, $driver);

        $this->assertSame([
            'free_attempts' => 5,
            'retry_fee' => 8.5,
            'charge_enabled' => true,
            'amount' => 8.5,
        ], $driver->feeConfig()->toArray());

        $payload = [
            'certify_id' => 'CERT-PLUGIN-CALLBACK',
            'timestamp' => (string) now()->timestamp,
            'nonce' => 'nonce-'.bin2hex(random_bytes(4)),
        ];
        $payload['sign'] = hash_hmac('sha256', $this->canonicalVerificationPayload($payload), 'callback-secret');

        $result = $driver->verifyCallback(new VerificationCallbackRequest(
            payload: $payload,
            headers: [],
            method: 'POST',
            path: 'api/v2/client/verification/callback',
            rawBody: '',
        ));

        $this->assertTrue($result->passed);
        $this->assertSame('CERT-PLUGIN-CALLBACK|'.$payload['nonce'], $result->replayKey);

        $badPayload = $payload;
        $badPayload['sign'] = 'invalid-signature';
        $this->assertFalse($driver->verifyCallback(new VerificationCallbackRequest(
            payload: $badPayload,
            headers: [],
            method: 'POST',
            path: 'api/v2/client/verification/callback',
            rawBody: '',
        ))->passed);
    }

    public function test_sms_manager_prefers_enabled_sms_plugin_without_duplicate_registration(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('sms', 'aliyun', [
            'access_key' => 'sms-access-key',
            'secret_key' => 'sms-secret-key',
            'sign_name' => '图拉云',
        ]);

        $this->app->forgetInstance(SmsDriverManager::class);

        $driver = app(SmsDriverManager::class)->resolve('aliyun');

        $this->assertSame(PluginSmsDriver::class, $driver::class);
        $this->assertTrue(class_exists(AliyunPlugin::class));
        $this->assertTrue(class_exists(AliyunSmsService::class));
        $this->assertSame([['value' => 'aliyun', 'label' => $driver->label()]], app(SmsDriverManager::class)->options());
    }

    public function test_upstream_registry_prefers_enabled_upstream_plugin_without_duplicate_registration(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'zjmf_finance', []);

        $this->app->forgetInstance(ProviderRegistry::class);

        $registry = app(ProviderRegistry::class);
        $zjmfDriver = $registry->find(ProviderKey::ZJMF_FINANCE_API);

        $this->assertNotNull($zjmfDriver);
        $this->assertSame(PluginUpstreamDriver::class, $zjmfDriver::class);
        $this->assertTrue(class_exists(ZjmfFinancePlugin::class));
        $this->assertTrue(class_exists(ZjmfFinance::class));
        $this->assertContains(ProvidesConsoleCatalog::class, $zjmfDriver->capabilities());
        $zjmfCatalog = $zjmfDriver->resolve(ProvidesConsoleCatalog::class);
        $this->assertInstanceOf(ProvidesConsoleCatalog::class, $zjmfCatalog);
        $this->assertInstanceOf(ZjmfFinanceAdapter::class, $zjmfCatalog);
        $this->assertSame([ProviderKey::ZJMF_FINANCE_API], $registry->keys());
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
        if ($config !== []) {
            $configRepository->save($plugin, $manifest, $config);
        }

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

    private function invokeStay33ClientMethod(Stay33Client $client, string $method): mixed
    {
        $reflection = new \ReflectionMethod($client, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }

    private function invokeBaiduFaceClientMethod(BaiduFaceClient $client, string $method): mixed
    {
        $reflection = new \ReflectionMethod($client, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($client);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalVerificationPayload(array $payload): string
    {
        unset($payload['sign'], $payload['signature']);
        $this->ksortRecursive($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ksortRecursive(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
