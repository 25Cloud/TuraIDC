<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\CouponStatus;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponCampaign;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminCatalogActionApiTest extends TestCase
{
    public function test_catalog_actions_require_login_and_product_manage_permission(): void
    {
        $product = $this->createProduct();

        $this->patchJson('/api/v2/admin/products/'.$product->id.'/status', ['enabled' => false])
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->patchJson('/api/v2/admin/products/'.$product->id.'/status', ['enabled' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_product_status_action_uses_explicit_enabled_and_small_projection(): void
    {
        $product = $this->createProduct(['status' => 1]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $this->patchJson('/api/v2/admin/products/'.$product->id.'/status', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['enabled', 'per_page']]]);

        $response = $this->patchJson('/api/v2/admin/products/'.$product->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.product.id', $product->id)
            ->assertJsonPath('data.detail.product.status', 0);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(0, (int) $product->refresh()->status);

        $this->patchJson('/api/v2/admin/products/'.$product->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.detail.product.status', 0);
    }

    public function test_coupon_and_campaign_status_actions_are_explicit_and_whitelisted(): void
    {
        $coupon = $this->createCoupon(['status' => CouponStatus::ACTIVE]);
        $campaign = $this->createCampaign(['status' => CouponStatus::DISABLED]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $couponResponse = $this->patchJson('/api/v2/admin/coupons/'.$coupon->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.detail.coupon.id', $coupon->id)
            ->assertJsonPath('data.detail.coupon.status', CouponStatus::DISABLED);

        $campaignResponse = $this->patchJson('/api/v2/admin/coupon-campaigns/'.$campaign->id.'/status', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.detail.coupon_campaign.id', $campaign->id)
            ->assertJsonPath('data.detail.coupon_campaign.status', CouponStatus::ACTIVE);

        $this->assertSame($this->actionResultWhitelist(), array_keys($couponResponse->json('data')));
        $this->assertSame($this->actionResultWhitelist(), array_keys($campaignResponse->json('data')));
        $this->assertNoSensitiveKeys($couponResponse->json());
        $this->assertNoSensitiveKeys($campaignResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $couponResponse->getContent()));
        $this->assertLessThan(100 * 1024, strlen((string) $campaignResponse->getContent()));
        $this->assertSame(CouponStatus::DISABLED, (int) $coupon->refresh()->status);
        $this->assertSame(CouponStatus::ACTIVE, (int) $campaign->refresh()->status);
    }

    public function test_coupon_campaign_task_requires_type_and_compacts_trigger_result(): void
    {
        $campaign = $this->createCampaign(['status' => CouponStatus::ACTIVE]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_MANAGE]));

        $this->postJson('/api/v2/admin/coupon-campaigns/'.$campaign->id.'/tasks', ['type' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['type']]]);

        $response = $this->postJson('/api/v2/admin/coupon-campaigns/'.$campaign->id.'/tasks', [
            'type' => 'trigger',
            'payload' => [],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'trigger')
            ->assertJsonPath('data.detail.result.campaign_id', $campaign->id)
            ->assertJsonPath('data.detail.result.campaign_status', CouponStatus::ACTIVE);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertGreaterThan(0, (int) $response->json('data.detail.result.coupon_id'));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return list<string>
     */
    private function actionResultWhitelist(): array
    {
        return [
            'id',
            'status',
            'message',
            'detail',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(array $overrides = []): Product
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'v2-action-'.$suffix,
            'name' => '动作分组 '.$suffix,
            'slug' => 'v2-action-'.$suffix,
            'description' => '动作分组',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'v2-action-'.$suffix,
        ]);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '动作二级 '.$suffix,
            'slug' => 'v2-action-second-'.$suffix,
            'description' => '动作二级',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);

        return Product::query()->create(array_replace([
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_id' => (int) $secondGroup->id,
            'third_product_group_id' => null,
            'service_type_code' => (string) $firstGroup->code,
            'custom_display_name' => '动作商品 '.$suffix,
            'product_type' => (string) $firstGroup->code,
            'pricing' => [
                'monthly' => '19.00',
            ],
            'setup_fee' => '0.00',
            'purchase_requires' => ['api_key' => 'should-not-leak'],
            'config_options' => [['secret' => 'should-not-leak']],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 1,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCoupon(array $overrides = []): Coupon
    {
        $suffix = bin2hex(random_bytes(4));

        return Coupon::query()->create(array_merge([
            'name' => '动作优惠券 '.$suffix,
            'code' => 'V2ACTION'.strtoupper($suffix),
            'description' => '动作优惠券',
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'min_amount' => '0.00',
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'total_usage_limit' => null,
            'per_user_limit' => null,
            'used_count' => 0,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => 0,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
            'operator' => 'v2-action',
            'trace_id' => 'v2-action-'.$suffix,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCampaign(array $overrides = []): CouponCampaign
    {
        $suffix = bin2hex(random_bytes(4));

        return CouponCampaign::query()->create(array_merge([
            'name' => '动作活动 '.$suffix,
            'description' => null,
            'weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'trigger_time' => '08:00:00',
            'issue_quantity' => 10,
            'valid_duration_hours' => 24,
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'per_user_limit' => null,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => 0,
            'last_dispatched_at' => null,
            'last_coupon_id' => null,
            'remark' => null,
            'operator' => 'v2-action',
            'trace_id' => 'v2-action-'.$suffix,
        ], $overrides));
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-action-'.$suffix,
            'label' => 'V2 Action',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-action-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Action',
            'email' => 'v2-action-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
