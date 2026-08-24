<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OpenApiFlowTest extends TestCase
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
            'email' => "open-flow-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Open API 流程测试用户',
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

    private function createKey(User $user, array $scopes): array
    {
        return app(ApiKeyService::class)->createForUser($user, '流程测试', $scopes);
    }

    private function scopesAll(): array
    {
        return [
            'products' => 'read',
            'orders' => 'write',
            'services' => 'write',
            'finance' => 'read',
        ];
    }

    public function test_missing_or_invalid_secret_rejected(): void
    {
        $this->getJson('/api/v2/open/balance')->assertStatus(401)->assertJsonPath('code', 40100);
        $this->withToken('tura_invalid_token_xyz')->getJson('/api/v2/open/balance')->assertStatus(401);
    }

    public function test_products_and_balance_accessible_with_full_key(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, $this->scopesAll());

        $this->withToken($plain)->getJson('/api/v2/open/products')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['list']]);

        $this->withToken($plain)->getJson('/api/v2/open/balance')
            ->assertOk()
            ->assertJsonStructure(['data' => ['balance']]);
    }

    public function test_self_key_info_and_disable(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, $this->scopesAll());

        $this->withToken($plain)->getJson('/api/v2/open/keys/self')
            ->assertOk()
            ->assertJsonPath('data.key_prefix', $key->key_prefix);

        $this->withToken($plain)->postJson('/api/v2/open/keys/self/disable')->assertOk();

        $this->withToken($plain)->getJson('/api/v2/open/balance')->assertStatus(401);
        $this->assertSame(ApiKey::STATUS_DISABLED, ApiKey::query()->find($key->id)?->status);
    }

    public function test_nonexistent_product_returns_404(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['products' => 'read']);

        $this->withToken($plain)->getJson('/api/v2/open/products/999999')->assertNotFound();
        $this->withToken($plain)->getJson('/api/v2/open/products/999999/quotes?billing_cycle=monthly')->assertNotFound();
    }

    public function test_order_store_requires_quote_token_and_idempotency_key(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['orders' => 'write']);

        $this->withToken($plain)->postJson('/api/v2/open/orders', [
            'product_id' => 1,
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);
    }

    public function test_read_only_key_cannot_order(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['orders' => 'read']);

        $this->withToken($plain)->postJson('/api/v2/open/orders', [
            'product_id' => 1,
            'billing_cycle' => 'monthly',
            'quote_token' => 'x',
            'idempotency_key' => 'k',
        ])->assertStatus(403);
    }

    public function test_usage_log_is_recorded(): void
    {
        $user = $this->createClientUser();
        [$key, $plain] = $this->createKey($user, ['finance' => 'read']);

        $this->withToken($plain)->getJson('/api/v2/open/balance')->assertOk();

        $this->assertDatabaseHas('api_key_usage_logs', [
            'api_key_id' => (int) $key->id,
            'path' => 'api/v2/open/balance',
            'status_code' => 200,
        ]);
    }
}
