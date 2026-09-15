<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZjmfUpstream\UpstreamApiCredentialService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 用户自助开启「魔方财务上游 API」凭据的闭环回归。
 *
 * 此前 users.api_open/api_username/api_password 三个字段没有任何设置入口，
 * 魔方财务对接第一步（/zjmf_api_login）必然鉴权失败。本测试覆盖：
 * 开启 → 用生成的凭据真实登录上游接口 → 关停后立即失效。
 */
class UpstreamApiCredentialTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function enable_generates_credentials_that_can_login_to_upstream_api(): void
    {
        $user = $this->makeUser();

        $enableResponse = $this->actingAs($user)
            ->postJson('/api/v2/client/upstream-api/enable')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $username = (string) $enableResponse->json('data.username');
        $password = (string) $enableResponse->json('data.password');

        $this->assertNotSame('', $username);
        $this->assertNotSame('', $password);

        $user->refresh();
        $this->assertSame(1, (int) $user->api_open);
        $this->assertSame($username, (string) $user->api_username);
        // 明文不落库
        $this->assertNotSame($password, (string) $user->api_password);

        // 用生成的凭据真实登录魔方财务上游登录端点
        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $username,
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['jwt']);
    }

    #[Test]
    public function disable_invalidates_credentials_immediately(): void
    {
        $user = $this->makeUser();

        $credential = app(UpstreamApiCredentialService::class)->enable($user);
        $user->refresh();

        $this->actingAs($user->refresh())
            ->postJson('/api/v2/client/upstream-api/disable')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $user->refresh();
        $this->assertSame(0, (int) $user->api_open);
        $this->assertNull($user->api_username);

        // 关停后登录必须失败
        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function status_reports_configuration_without_leaking_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/v2/client/upstream-api')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonMissingPath('data.password');

        app(UpstreamApiCredentialService::class)->enable($user);

        $response = $this->actingAs($user->refresh())
            ->getJson('/api/v2/client/upstream-api')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.has_password', true);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertStringContainsString('/api/v2/zjmf/zjmf_api_login', (string) ($payload['login_url'] ?? ''));
    }

    #[Test]
    public function reset_password_rotates_secret_and_keeps_username(): void
    {
        $user = $this->makeUser();
        $original = app(UpstreamApiCredentialService::class)->enable($user);
        $user->refresh();

        $resetResponse = $this->actingAs($user)
            ->postJson('/api/v2/client/upstream-api/reset-password')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $newPassword = (string) $resetResponse->json('data.password');
        $this->assertSame($original['username'], (string) $resetResponse->json('data.username'));
        $this->assertNotSame($original['password'], $newPassword);

        // 旧密码失效、新密码可用
        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $original['username'],
            'password' => $original['password'],
        ])->assertJsonPath('status', 400);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $original['username'],
            'password' => $newPassword,
        ])->assertJsonPath('status', 200);
    }

    #[Test]
    public function reset_password_requires_api_enabled(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/v2/client/upstream-api/reset-password')
            ->assertStatus(422);
    }

    private function makeUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => "upstream-api-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $this->userIds[] = (int) $user->id;

        return $user;
    }
}
