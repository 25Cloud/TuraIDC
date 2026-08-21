<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;
use TuraIDC\Plugins\Gateways\AliPay\Lib\AlipayClient;

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

    public function test_alipay_client_passes_configured_ca_bundle_to_http_client(): void
    {
        $caBundle = tempnam(sys_get_temp_dir(), 'alipay-ca-');
        $this->assertIsString($caBundle);
        file_put_contents($caBundle, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        try {
            config([
                'alipay.ssl_verify' => true,
                'alipay.ca_bundle' => $caBundle,
            ]);

            $client = $this->makeAlipayClient();
            $pendingRequest = $this->invokePrivateMethod($client, 'buildHttpClient');

            $this->assertSame($caBundle, $pendingRequest->getOptions()['verify'] ?? null);
        } finally {
            @unlink($caBundle);
        }
    }

    public function test_alipay_client_can_disable_ssl_verification_from_config(): void
    {
        config([
            'alipay.ssl_verify' => false,
            'alipay.ca_bundle' => '',
        ]);

        $client = $this->makeAlipayClient();
        $pendingRequest = $this->invokePrivateMethod($client, 'buildHttpClient');

        $this->assertFalse($pendingRequest->getOptions()['verify'] ?? null);
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
