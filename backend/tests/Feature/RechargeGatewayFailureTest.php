<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use TuraIDC\Plugins\Gateways\AliPay\Lib\AlipayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class RechargeGatewayFailureTest extends TestCase
{
    public function test_alipay_precreate_connection_failure_returns_business_exception(): void
    {
        config([
            'alipay.gateway' => 'https://openapi.alipay.com/gateway.do',
            'alipay.notify_url' => 'https://example.com/api/v2/client/payment/alipay/notify',
            'alipay.app_id' => 'test-app-id',
            'alipay.private_key' => str_repeat('A', 200),
        ]);

        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $service = $this->makeAlipayClient();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('支付网关暂时不可用，请稍后重试');

        $this->invokePrivateMethod($service, 'request', [[
            'app_id' => 'test-app-id',
            'method' => 'alipay.trade.precreate',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => '{}',
            'sign' => 'signature',
        ]]);
    }

    public function test_alipay_client_ignores_plugin_runtime_gateway_and_notify_config(): void
    {
        config([
            'alipay.gateway' => 'https://openapi.alipay.com/gateway.do',
            'alipay.notify_url' => '',
            'app.url' => 'https://api.example.test',
            'app.frontend_url' => 'https://console.example.test',
        ]);

        $client = new AlipayClient([
            'gateway' => 'https://plugin-gateway.example.test/gateway.do',
            'notify_url' => 'https://pay.example.test/api/v2/client/payment/alipay/notify',
        ]);

        $this->assertSame('https://openapi.alipay.com/gateway.do', $this->getPrivateProperty($client, 'gateway'));
        $this->assertSame('https://api.example.test/api/v2/client/payment/alipay/notify', $this->getPrivateProperty($client, 'notifyUrl'));
    }

    /**
     * 证书校验不再可配置：支付网关插件不得关闭校验，也不得指定自定义 CA。
     *
     * 本测试取代原 test_alipay_client_passes_configured_ca_bundle_to_http_client 与
     * test_alipay_client_can_disable_ssl_verification_from_config——那两条断言可经
     * config('alipay.ssl_verify' / 'alipay.ca_bundle') 改变校验行为，把违规行为钉成了预期，
     * 与 AGENTS.md「所有插件不需要 SSL 和 CA」冲突。支付回调链路上留这条通路风险最高。
     */
    public function test_alipay_client_always_verifies_certificates(): void
    {
        // 即便把旧配置显式关成 false 并给出 CA 路径，也不应影响出网客户端
        config([
            'alipay.ssl_verify' => false,
            'alipay.ca_bundle' => '/tmp/should-be-ignored.pem',
        ]);

        $client = $this->makeAlipayClient();
        $pendingRequest = $this->invokePrivateMethod($client, 'buildHttpClient');

        // 不显式设置 verify，交由 Guzzle 默认值（true）与系统 CA 生效
        $this->assertArrayNotHasKey('verify', $pendingRequest->getOptions());
        $this->assertFalse(method_exists($client, 'httpVerifyOption'));
    }

    public function test_precreate_notify_url_accepts_public_https_address(): void
    {
        config([
            'alipay.notify_url' => 'https://pay.example.com/api/v2/client/payment/alipay/notify',
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        $service = $this->makeAlipayClient();

        $this->assertSame(
            'https://pay.example.com/api/v2/client/payment/alipay/notify',
            $this->invokePrivateMethod($service, 'resolvePrecreateNotifyUrl')
        );
    }

    public function test_precreate_notify_url_accepts_public_http_address(): void
    {
        config([
            'alipay.notify_url' => 'http://47.109.144.223:6107/api/v2/client/payment/alipay/notify',
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        $service = $this->makeAlipayClient();

        $this->assertSame(
            'http://47.109.144.223:6107/api/v2/client/payment/alipay/notify',
            $this->invokePrivateMethod($service, 'resolvePrecreateNotifyUrl')
        );
    }

    public function test_precreate_notify_url_falls_back_to_backend_url(): void
    {
        config([
            'alipay.notify_url' => '',
            'app.url' => 'http://47.109.144.223:6107',
            'app.frontend_url' => 'http://console.example.test',
        ]);

        $service = $this->makeAlipayClient();

        $this->assertSame(
            'http://47.109.144.223:6107/api/v2/client/payment/alipay/notify',
            $this->invokePrivateMethod($service, 'resolvePrecreateNotifyUrl')
        );
    }

    public function test_precreate_notify_url_rejects_local_backend_address(): void
    {
        config([
            'alipay.notify_url' => '',
            'app.frontend_url' => '',
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        $service = $this->makeAlipayClient();

        $this->assertNull($this->invokePrivateMethod($service, 'resolvePrecreateNotifyUrl'));
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }

    private function makeAlipayClient(): AlipayClient
    {
        $manifest = app(PluginScanner::class)->requireManifest('payment', 'ali_pay');
        app(PluginFileLoader::class)->ensureLoaded($manifest);

        return new AlipayClient;
    }

    private function getPrivateProperty(object $target, string $property): mixed
    {
        $reflection = new \ReflectionProperty($target, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($target);
    }
}
