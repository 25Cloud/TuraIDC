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
}
