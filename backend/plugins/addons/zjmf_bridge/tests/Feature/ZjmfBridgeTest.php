<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfErrorMapper;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;

class ZjmfBridgeTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => false,
            'zjmf_bridge.app_id' => 'zjmf-test',
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.allowed_ips' => [],
            'zjmf_bridge.signature_tolerance' => 300,
            'zjmf_bridge.system_scopes' => ['system.health'],
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_bridge_is_closed_by_default(): void
    {
        $response = $this->getJson('/zjmf/v1/health');

        $response
            ->assertStatus(404)
            ->assertJsonPath('status', 503)
            ->assertJsonPath('msg', 'ZJMF Bridge 未启用');
    }

    public function test_enabled_health_check_uses_zjmf_response_shape(): void
    {
        config(['zjmf_bridge.enabled' => true]);

        $response = $this->getJson('/zjmf/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', 'success')
            ->assertJsonPath('data.service', 'zjmf_bridge')
            ->assertJsonPath('data.enabled', true);
    }

    public function test_hmac_signature_allows_system_route(): void
    {
        config(['zjmf_bridge.enabled' => true]);

        $response = $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/system/health'))
            ->get('/zjmf/v1/system/health', ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.scope', 'system.health');
    }

    public function test_hmac_signature_rejects_invalid_signature(): void
    {
        config(['zjmf_bridge.enabled' => true]);

        $headers = $this->signedHeaders('GET', '/zjmf/v1/system/health');
        $headers['X-ZJMF-Signature'] = str_repeat('0', 64);

        $response = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/system/health', ['Accept' => 'application/json']);

        $response
            ->assertStatus(401)
            ->assertJsonPath('status', 401)
            ->assertJsonPath('msg', '签名校验失败');
    }

    public function test_hmac_signature_rejects_replayed_nonce(): void
    {
        config(['zjmf_bridge.enabled' => true]);

        $headers = $this->signedHeaders('GET', '/zjmf/v1/system/health', nonce: 'nonce-replay');

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/system/health', ['Accept' => 'application/json'])
            ->assertOk();

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/system/health', ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('status', 409)
            ->assertJsonPath('msg', '重复请求');
    }

    public function test_hmac_signature_rejects_expired_timestamp(): void
    {
        config(['zjmf_bridge.enabled' => true]);

        $headers = $this->signedHeaders(
            'GET',
            '/zjmf/v1/system/health',
            timestamp: time() - 600
        );

        $response = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/system/health', ['Accept' => 'application/json']);

        $response
            ->assertStatus(401)
            ->assertJsonPath('status', 401)
            ->assertJsonPath('msg', '签名时间戳已过期');
    }

    public function test_token_service_issues_and_verifies_hmac_jwt(): void
    {
        config(['zjmf_bridge.secret' => 'zjmf-test-secret']);

        $payload = app(ZjmfTokenService::class)->verify(
            app(ZjmfTokenService::class)->issue([
                'sub' => 'client:123',
                'uid' => 123,
                'scope' => ['profile.read'],
            ], 60)
        );

        $this->assertIsArray($payload);
        $this->assertSame('client:123', $payload['sub']);
        $this->assertSame(123, $payload['uid']);
        $this->assertSame(['profile.read'], $payload['scope']);
    }

    public function test_error_mapper_converts_TURAIDC_codes_to_zjmf_statuses(): void
    {
        $mapper = app(ZjmfErrorMapper::class);

        $this->assertSame(200, $mapper->fromTuraIDCCode(0));
        $this->assertSame(401, $mapper->fromTuraIDCCode(40100));
        $this->assertSame(403, $mapper->fromTuraIDCCode(40300));
        $this->assertSame(422, $mapper->fromTuraIDCCode(42200));
        $this->assertSame(500, $mapper->fromTuraIDCCode(50000));
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $path, ?int $timestamp = null, string $nonce = 'nonce-001'): array
    {
        $timestamp ??= time();
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            '',
            hash('sha256', ''),
            (string) $timestamp,
            $nonce,
        ]);

        return [
            'X-ZJMF-App-Id' => 'zjmf-test',
            'X-ZJMF-Timestamp' => (string) $timestamp,
            'X-ZJMF-Nonce' => $nonce,
            'X-ZJMF-Signature' => hash_hmac('sha256', $canonical, 'zjmf-test-secret'),
        ];
    }
}
