<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VncRelayCommand;
use App\Services\Auth\VerificationService;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\ClientServiceConsole\ServiceVncService;
use App\Services\System\OperationLogService;
use ReflectionMethod;
use Tests\TestCase;

class ConfigRuntimeAccessTest extends TestCase
{
    public function test_verification_qrcode_payload_returns_proxy_url_without_extra_proxy_field(): void
    {
        $content = file_get_contents(base_path('app/Services/Auth/VerificationService.php'));

        $this->assertIsString($content);
        $this->assertStringContainsString("'url' => \$proxyUrl", $content);
        $this->assertStringNotContainsString("'proxy_url' =>", $content);
    }

    public function test_verification_service_builds_http_urls_from_api_origin(): void
    {
        config([
            'app.frontend_url' => 'http://www.example.test',
            'app.url' => 'http://api.example.test:8000',
        ]);

        $service = app(VerificationService::class);

        $this->assertSame(
            'http://api.example.test:8000/api/v2/client/verification/callback',
            $this->invokePrivateMethod($service, 'resolveCallbackUrl')
        );
        $this->assertSame(
            'http://api.example.test:8000/api/v2/client/verification/scan?certify_id=cert-123',
            $this->invokePrivateMethod($service, 'buildQrCodeProxyUrl', ['cert-123'])
        );
    }

    public function test_vnc_uses_console_for_viewer_and_api_for_requests(): void
    {
        config([
            'app.url' => 'https://api.example.test',
            'app.frontend_url' => 'https://www.example.test',
            'app.client_console_url' => 'https://console.example.test',
            'app.admin_url' => 'https://admin.example.test',
        ]);

        $service = $this->makeVncService();

        $this->assertSame(
            'https://console.example.test/vnc',
            $this->invokePrivateMethod($service, 'resolveNoVncBaseUrl')
        );
        $this->assertSame(
            'https://api.example.test',
            $this->invokePrivateMethod($service, 'resolveVncApiBase')
        );
        $this->assertSame(
            'https://console.example.test',
            $this->invokePrivateMethod($service, 'resolveAllowedVncOrigin')
        );
    }

    public function test_vnc_supports_http_api_origin_for_local_development(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'app.client_console_url' => 'http://127.0.0.1:5173',
        ]);

        $service = $this->makeVncService();

        $this->assertSame('http://127.0.0.1:5173/vnc', $this->invokePrivateMethod($service, 'resolveNoVncBaseUrl'));
        $this->assertSame('http://127.0.0.1:8000', $this->invokePrivateMethod($service, 'resolveVncApiBase'));
    }

    public function test_vnc_relay_command_uses_console_origin_when_upstream_does_not_supply_one(): void
    {
        config(['app.client_console_url' => 'https://console.example.test']);

        $command = new VncRelayCommand;

        $this->assertSame('https://console.example.test', $this->invokePrivateMethod($command, 'resolveOriginHeader'));
        $this->assertSame(
            'https://upstream.example.test',
            $this->invokePrivateMethod($command, 'resolveOriginHeader', [['origin' => 'https://upstream.example.test']])
        );
    }

    public function test_vnc_relay_allows_only_console_origin_before_consuming_a_token(): void
    {
        config(['app.client_console_url' => 'https://console.example.test']);

        $command = new VncRelayCommand;
        $params = ['allowed_origin' => 'https://console.example.test'];

        $this->assertTrue($this->invokePrivateMethod($command, 'isAllowedClientOrigin', [
            'https://console.example.test',
            $params,
        ]));
        $this->assertFalse($this->invokePrivateMethod($command, 'isAllowedClientOrigin', [
            '',
            $params,
        ]));
        $this->assertFalse($this->invokePrivateMethod($command, 'isAllowedClientOrigin', [
            'https://admin.example.test',
            $params,
        ]));
        $this->assertFalse($this->invokePrivateMethod($command, 'isAllowedClientOrigin', [
            'https://admin.example.test',
        ]));

        $rejectedConsoleService = $this->createMock(ClientServiceConsoleService::class);
        $rejectedConsoleService->expects($this->once())
            ->method('previewVncToken')
            ->with('launch-token')
            ->willReturn($params);
        $rejectedConsoleService->expects($this->never())
            ->method('resolveVncToken');

        $this->assertNull($this->invokePrivateMethod($command, 'resolveVncTokenForClient', [
            $rejectedConsoleService,
            'launch-token',
            'https://admin.example.test',
        ]));

        $acceptedConsoleService = $this->createMock(ClientServiceConsoleService::class);
        $acceptedConsoleService->expects($this->once())
            ->method('previewVncToken')
            ->with('launch-token')
            ->willReturn($params);
        $acceptedConsoleService->expects($this->once())
            ->method('resolveVncToken')
            ->with('launch-token')
            ->willReturn(['host' => 'vnc.example.test']);

        $this->assertSame(['host' => 'vnc.example.test'], $this->invokePrivateMethod($command, 'resolveVncTokenForClient', [
            $acceptedConsoleService,
            'launch-token',
            'https://console.example.test',
        ]));
    }

    public function test_runtime_target_files_no_longer_use_env_directly(): void
    {
        $files = [
            base_path('app/Services/ClientServiceConsole/ServiceVncService.php'),
            base_path('app/Services/Auth/VerificationService.php'),
            base_path('app/Console/Commands/VncRelayCommand.php'),
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            $this->assertIsString($content);
            $this->assertStringNotContainsString('env(', $content, $file);
        }
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }

    private function makeVncService(): ServiceVncService
    {
        return new ServiceVncService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
        );
    }
}
