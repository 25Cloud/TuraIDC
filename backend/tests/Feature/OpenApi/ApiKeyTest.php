<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValues('open_api', ['enabled' => '1']);
    }

    private function createClientUser(array $attributes = []): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create(array_merge([
            'email' => "open-api-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Open API 测试用户',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 0,
            'phone_change_alert' => 0,
            'email_change_alert' => 0,
        ], $attributes));
    }

    private function actingAsClient(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function createKey(User $user, array $scopes = ['products' => 'read'], array $attributes = []): array
    {
        return app(ApiKeyService::class)->createForUser(
            $user,
            (string) ($attributes['name'] ?? '测试密钥'),
            $scopes,
            $attributes['expires_at'] ?? null,
            $attributes['ip_allowlist'] ?? [],
        );
    }

    public function test_client_can_create_api_key_returns_secret_once(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $this->postJson('/api/v2/client/api-keys', [
            'name' => '下游对接',
            'scopes' => ['products' => 'read', 'orders' => 'write'],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['key' => ['key_prefix', 'secret_last4'], 'secret']]);

        $response = $this->postJson('/api/v2/client/api-keys', [
            'name' => '下游对接2',
            'scopes' => ['products' => 'read'],
        ])->assertOk();

        $payload = $response->json('data');
        $this->assertStringStartsWith(ApiKey::KEY_PREFIX, (string) $payload['key']['key_prefix']);
        $this->assertSame(4, strlen((string) $payload['key']['secret_last4']));

        $stored = ApiKey::query()->where('key_prefix', $payload['key']['key_prefix'])->first();
        $this->assertNotNull($stored);
        $this->assertNotSame($payload['secret'], $stored->secret_hash);
        $this->assertTrue(hash_equals($stored->secret_hash, app(ApiKeyService::class)->hashSecret((string) $payload['secret'])));
    }

    public function test_create_requires_phone_when_enabled(): void
    {
        Setting::setValues('open_api', ['enabled' => '1', 'require_phone' => '1']);
        $user = $this->createClientUser(['phone' => '']);
        $this->actingAsClient($user);

        $this->postJson('/api/v2/client/api-keys', [
            'name' => '无手机',
            'scopes' => ['products' => 'read'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', '请先绑定手机号后再创建 API 密钥');
    }

    public function test_create_requires_verification_when_enabled(): void
    {
        Setting::setValues('open_api', ['enabled' => '1', 'require_verified' => '1']);
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $this->postJson('/api/v2/client/api-keys', [
            'name' => '未实名',
            'scopes' => ['products' => 'read'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', '请先完成实名认证后再创建 API 密钥');
    }

    public function test_create_respects_max_keys_per_user(): void
    {
        Setting::setValues('open_api', ['enabled' => '1', 'max_keys_per_user' => '1']);
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $this->postJson('/api/v2/client/api-keys', ['name' => '第一个', 'scopes' => ['products' => 'read']])->assertOk();

        $this->postJson('/api/v2/client/api-keys', ['name' => '第二个', 'scopes' => ['products' => 'read']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'API 密钥数量已达上限');
    }

    public function test_client_can_disable_enable_and_delete(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);
        [$key, $plain] = $this->createKey($user);

        $this->putJson("/api/v2/client/api-keys/{$key->id}/status", ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.key.status', 'disabled');

        $this->putJson("/api/v2/client/api-keys/{$key->id}/status", ['status' => 'enabled'])->assertOk();

        $this->deleteJson("/api/v2/client/api-keys/{$key->id}")->assertOk();
        $this->assertNull(ApiKey::query()->find($key->id));
    }

    public function test_cannot_manage_other_users_keys(): void
    {
        $owner = $this->createClientUser();
        $other = $this->createClientUser();
        [$key] = $this->createKey($owner);

        $this->actingAsClient($other);
        $this->putJson("/api/v2/client/api-keys/{$key->id}/status", ['status' => 'disabled'])->assertNotFound();
        $this->deleteJson("/api/v2/client/api-keys/{$key->id}")->assertNotFound();
        $this->getJson("/api/v2/client/api-keys/{$key->id}/usage-logs")->assertNotFound();
    }

    public function test_disabled_key_cannot_authenticate_open_api(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user);
        $key->forceFill(['status' => ApiKey::STATUS_DISABLED])->save();

        $this->withToken($plain)->getJson('/api/v2/open/balance')
            ->assertStatus(401)
            ->assertJsonPath('code', 40100);
    }

    public function test_expired_key_cannot_authenticate_open_api(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['products' => 'read'], [
            'expires_at' => now()->subDay()->toDateTimeString(),
        ]);

        $this->withToken($plain)->getJson('/api/v2/open/products')
            ->assertStatus(401)
            ->assertJsonPath('message', 'API 密钥已过期');
    }

    public function test_ip_allowlist_enforced(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['finance' => 'read'], [
            'ip_allowlist' => ['203.0.113.9'],
        ]);

        $this->withToken($plain)->getJson('/api/v2/open/balance')
            ->assertStatus(403)
            ->assertJsonPath('message', '当前 IP 不在密钥白名单内');
    }

    public function test_scope_enforced(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['products' => 'read']);

        $this->withToken($plain)->getJson('/api/v2/open/balance')
            ->assertStatus(403)
            ->assertJsonPath('message', '当前密钥缺少 finance:read 权限');
    }

    public function test_write_scope_implies_read(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['orders' => 'write']);

        $this->withToken($plain)->getJson('/api/v2/open/orders')->assertOk();
    }

    /**
     * N4 回归：只带 name 的部分更新，绝不能把 expires_at / ip_allowlist 静默清空。
     *
     * 原实现对这两项用 isset()?:null，导致 {"name":"x"} 会把密钥悄悄改成永不过期 + 不限 IP —
     * 等于拆掉两道安全控制。这条用例走完整 HTTP 栈（FormRequest→controller→service），
     * 因为缺陷正出在 validated() 丢弃了未提交字段这一层。
     */
    public function test_partial_update_preserves_expiry_and_ip_allowlist(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);
        [$key] = $this->createKey($user, ['products' => 'read'], [
            'expires_at' => now()->addYear()->toDateTimeString(),
            'ip_allowlist' => ['203.0.113.9'],
        ]);

        $originalExpiry = $key->fresh()->expires_at?->toDateTimeString();
        $this->assertNotNull($originalExpiry, '前置：密钥应带有过期时间');

        $this->putJson("/api/v2/client/api-keys/{$key->id}", ['name' => '改个名字'])
            ->assertOk()
            ->assertJsonPath('data.key.name', '改个名字');

        $fresh = $key->fresh();
        $this->assertSame($originalExpiry, $fresh->expires_at?->toDateTimeString(), 'expires_at 不能被部分更新清空');
        $this->assertSame(['203.0.113.9'], $fresh->ip_allowlist, 'ip_allowlist 不能被部分更新清空');
    }

    /**
     * 边界：显式传 null / [] 仍应能清空——修复 N4 不能把「可清空」一起改没了。
     */
    public function test_explicit_values_can_still_clear_expiry_and_ip_allowlist(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);
        [$key] = $this->createKey($user, ['products' => 'read'], [
            'expires_at' => now()->addYear()->toDateTimeString(),
            'ip_allowlist' => ['203.0.113.9'],
        ]);

        $this->putJson("/api/v2/client/api-keys/{$key->id}", ['expires_at' => null])->assertOk();
        $afterExpiryClear = $key->fresh();
        $this->assertNull($afterExpiryClear->expires_at, '显式 expires_at=null 应清空过期时间');
        $this->assertSame(['203.0.113.9'], $afterExpiryClear->ip_allowlist, '这一步不应动到 ip_allowlist');

        $this->putJson("/api/v2/client/api-keys/{$key->id}", ['ip_allowlist' => []])->assertOk();
        $this->assertNull($key->fresh()->ip_allowlist, '显式 ip_allowlist=[] 应清空白名单');
    }

    /**
     * M10：改用 secret_hash 等值命中后，多把 enabled 密钥并存时仍要解析到正确的那一把。
     */
    public function test_resolve_selects_correct_key_among_many_enabled(): void
    {
        [$k1] = $this->createKey($this->createClientUser(), ['products' => 'read']);
        [$k2, $s2] = $this->createKey($this->createClientUser(), ['products' => 'read']);
        [$k3] = $this->createKey($this->createClientUser(), ['products' => 'read']);

        $resolved = app(ApiKeyService::class)->resolve($s2);

        $this->assertSame($k2->id, $resolved->id);
        $this->assertNotSame($k1->id, $resolved->id);
        $this->assertNotSame($k3->id, $resolved->id);
    }

    /**
     * M10：把已有但从未被执行的 open_api.rate_limit 接上——超过阈值应 429。
     *
     * 限流跑在 api.key 认证之前，故未带令牌也会计数：阈值设 1 时，第 2 个请求就该被挡下，
     * 走 ThrottleRequestsException 的统一中文 429（code=42900），而不是继续走到 401。
     */
    public function test_open_api_requests_are_rate_limited(): void
    {
        Setting::setValues('open_api', ['enabled' => '1', 'rate_limit' => '1']);

        $this->getJson('/api/v2/open/products')->assertStatus(401);

        $this->getJson('/api/v2/open/products')
            ->assertStatus(429)
            ->assertJsonPath('code', 42900);
    }

    /**
     * 未知权限域必须在入口被拒（422），而不是被 normalizeScopes 静默丢弃。
     * 否则把 products 拼成 product 时请求"成功"，权限却没生效。
     */
    public function test_create_rejects_unknown_scope_domain(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);

        $this->postJson('/api/v2/client/api-keys', [
            'name' => '拼错域名',
            'scopes' => ['product' => 'read'],
        ])->assertStatus(422);
    }

    public function test_update_rejects_unknown_scope_domain(): void
    {
        $user = $this->createClientUser();
        $this->actingAsClient($user);
        [$key] = $this->createKey($user, ['products' => 'read']);

        $this->putJson("/api/v2/client/api-keys/{$key->id}", [
            'scopes' => ['servicess' => 'write'],
        ])->assertStatus(422);

        // 既有合法权限不受这次失败影响。
        $this->assertSame(['products' => 'read'], $key->fresh()->scopes);
    }
}
