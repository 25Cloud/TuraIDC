<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiKeyUsageLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenApi\ApiKeyService;
use App\Services\ZjmfUpstream\UpstreamApiCredentialService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 用户自助开启「魔方财务上游 API」凭据的闭环回归。
 *
 * 此前 users.api_open/api_username/api_password 三个字段没有任何设置入口，
 * 魔方财务对接第一步（/zjmf_api_login）必然鉴权失败。本测试覆盖：
 * 开启 → 用生成的凭据真实登录上游接口 → 关停后立即失效；
 * 以及补齐后的治理能力：IP 白名单、有效期、调用审计、与开放接口的联动失效。
 */
class UpstreamApiCredentialTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        DB::connection()->table('api_key_usage_logs')->whereIn('user_id', $this->userIds)->delete();
        DB::connection()->table('api_keys')->whereIn('user_id', $this->userIds)->delete();
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

    #[Test]
    public function ip_allowlist_blocks_login_from_unlisted_address(): void
    {
        $user = $this->makeUser();
        $credential = app(UpstreamApiCredentialService::class)->enable($user);

        // 白名单只放行 203.0.113.0/24：默认的 127.0.0.1 必须被拒。
        // 白名单是「凭据泄漏后仍不可用」的最后一道，登录端点必须与中间件同口径。
        $this->actingAs($user->refresh())
            ->putJson('/api/v2/client/upstream-api/policy', [
                'ip_allowlist' => ['203.0.113.0/24'],
            ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);

        // 换回不限 IP 后可正常登录（证明拒绝原因确实是白名单，而非凭据本身失效）
        $user->refresh();
        app(UpstreamApiCredentialService::class)->updatePolicy($user, ['ip_allowlist' => []]);
        $this->assertSame([], app(UpstreamApiCredentialService::class)->status($user->refresh())['ip_allowlist']);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 200);
    }

    #[Test]
    public function expired_credentials_are_rejected_at_login(): void
    {
        $user = $this->makeUser();
        $credential = app(UpstreamApiCredentialService::class)->enable($user);

        $user->refresh();
        app(UpstreamApiCredentialService::class)->updatePolicy($user, [
            'expires_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $status = app(UpstreamApiCredentialService::class)->status($user->refresh());
        $this->assertTrue($status['is_expired']);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function login_is_audited_under_zjmf_channel(): void
    {
        $user = $this->makeUser();
        $credential = app(UpstreamApiCredentialService::class)->enable($user);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
        ])->assertJsonPath('status', 200);

        $log = ApiKeyUsageLog::query()
            ->where('user_id', (int) $user->id)
            ->where('channel', ApiKeyUsageLog::CHANNEL_ZJMF_UPSTREAM)
            ->first();

        $this->assertNotNull($log, '登录成功后必须留下 channel=zjmf_upstream 的审计记录');
        $this->assertSame('POST', (string) $log->method);
        $this->assertSame(200, (int) $log->status_code);
        $this->assertSame(0, (int) $log->api_key_id);

        // 控制台可见同一份记录
        $this->actingAs($user->refresh())
            ->getJson('/api/v2/client/upstream-api/usage-logs')
            ->assertOk()
            ->assertJsonPath('data.list.0.path', 'api/v2/zjmf/zjmf_api_login');

        // last_used_at 只在登录时回写，控制台能看到
        $this->assertNotNull($user->refresh()->api_last_used_at);
    }

    #[Test]
    public function disabling_upstream_also_disables_open_api_keys(): void
    {
        // 开放接口默认关闭，创建密钥前必须先由管理员开启（与 ApiKeyTest 同一前置）
        Setting::setValues('open_api', ['enabled' => '1']);

        $user = $this->makeUser();

        app(ApiKeyService::class)->createForUser($user, '联动失效测试', ['products' => 'read']);
        $this->assertSame(1, ApiKey::query()->where('user_id', (int) $user->id)->where('status', ApiKey::STATUS_ENABLED)->count());

        app(UpstreamApiCredentialService::class)->enable($user->refresh());
        $this->actingAs($user->refresh())
            ->postJson('/api/v2/client/upstream-api/disable')
            ->assertOk();

        // 只关一边会留下一个没有界面入口的全权凭据，因此两条链路的凭据一起失效
        $this->assertSame(0, ApiKey::query()->where('user_id', (int) $user->id)->where('status', ApiKey::STATUS_ENABLED)->count());
    }

    #[Test]
    public function policy_update_keeps_untouched_field(): void
    {
        $user = $this->makeUser();
        app(UpstreamApiCredentialService::class)->enable($user);

        $user->refresh();
        app(UpstreamApiCredentialService::class)->updatePolicy($user, [
            'ip_allowlist' => ['203.0.113.10'],
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        // 只改 IP 白名单：有效期必须保留（缺失=保留，显式空=清空）
        $user->refresh();
        app(UpstreamApiCredentialService::class)->updatePolicy($user, ['ip_allowlist' => ['198.51.100.0/24']]);

        $status = app(UpstreamApiCredentialService::class)->status($user->refresh());
        $this->assertSame(['198.51.100.0/24'], $status['ip_allowlist']);
        $this->assertNotNull($status['expires_at']);
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
