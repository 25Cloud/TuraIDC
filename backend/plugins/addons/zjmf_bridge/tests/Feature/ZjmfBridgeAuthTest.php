<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;

class ZjmfBridgeAuthTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.app_id' => 'zjmf-test',
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.allowed_ips' => [],
            'zjmf_bridge.signature_tolerance' => 300,
            'zjmf_bridge.token_ttl' => 7200,
            'zjmf_bridge.system_scopes' => ['auth.login'],
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_login_api_issues_zjmf_jwt_without_exposing_sanctum_token(): void
    {
        $user = $this->createClientUser();
        $payload = [
            'account' => (string) $user->email,
            'password' => 'Secret123!',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this
            ->withHeaders($this->signedHeaders('POST', '/zjmf/v1/login_api', body: $body ?: ''))
            ->postJson('/zjmf/v1/login_api', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '登录成功')
            ->assertJsonPath('data.token_type', 'JWT')
            ->assertJsonPath('data.expires_in', 7200)
            ->assertJsonPath('data.user.id', (int) $user->id)
            ->assertJsonPath('data.user.email', (string) $user->email);

        $jwt = (string) $response->json('data.jwt');
        $this->assertStringContainsString('.', $jwt);
        $this->assertStringNotContainsString('|', $jwt);
        $this->assertSame($jwt, $response->json('data.token'));
        $this->assertSame(0, PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', (int) $user->id)
            ->where('name', 'client-token')
            ->count());

        $this
            ->withHeaders(['Authorization' => 'JWT '.$jwt])
            ->get('/zjmf/v1/user', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.client.id', (int) $user->id)
            ->assertJsonPath('data.client.email', (string) $user->email)
            ->assertJsonPath('data.client.credit', (string) $user->balance);
    }

    public function test_user_route_rejects_invalid_zjmf_jwt(): void
    {
        $this
            ->withHeaders(['Authorization' => 'JWT invalid-token'])
            ->get('/zjmf/v1/user', ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('status', 401)
            ->assertJsonPath('msg', '未登录或登录已过期');
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-client-'.$suffix.'@example.com',
            'phone' => '139'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Client',
            'status' => 1,
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 0,
            'password_change_alert' => 0,
            'phone_change_alert' => 0,
            'email_change_alert' => 0,
            'marketing_alert' => 0,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(
        string $method,
        string $path,
        string $body = '',
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= 'nonce-'.bin2hex(random_bytes(8));
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            '',
            hash('sha256', $body),
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
