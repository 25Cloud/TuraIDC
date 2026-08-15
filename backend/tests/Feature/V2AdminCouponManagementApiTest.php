<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminCouponManagementApiTest extends TestCase
{
    public function test_admin_coupon_read_endpoints_require_permission_and_return_v2_contract(): void
    {
        $this->getJson('/api/v2/admin/coupons')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/coupons')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/coupons?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/coupons?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $summaryResponse = $this->getJson('/api/v2/admin/coupons/summary')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame($this->couponSummaryWhitelist(), array_keys($summaryResponse->json('data')));
        $this->assertNoSensitiveKeys($summaryResponse->json());
    }

    public function test_admin_coupon_create_update_list_and_delete_use_v2_resources(): void
    {
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::PRODUCT_LIST,
            AdminPermissions::PRODUCT_MANAGE,
        ]));

        $suffix = bin2hex(random_bytes(4));
        $createResponse = $this->postJson('/api/v2/admin/coupons', $this->couponPayload('V2 优惠券 '.$suffix))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '优惠券创建成功')
            ->assertJsonPath('data.coupon.name', 'V2 优惠券 '.$suffix);

        $couponId = (int) $createResponse->json('data.coupon.id');
        $this->assertSame($this->couponWhitelist(), array_keys($createResponse->json('data.coupon')));
        $this->assertNoSensitiveKeys($createResponse->json());

        $this->putJson('/api/v2/admin/coupons/'.$couponId, $this->couponPayload('V2 优惠券更新 '.$suffix, 15))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.coupon.name', 'V2 优惠券更新 '.$suffix)
            ->assertJsonPath('data.coupon.discount_value', '15.00');

        $listResponse = $this->getJson('/api/v2/admin/coupons?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 100)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $couponId);

        $this->assertSame($this->couponWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));
        $this->assertNoSensitiveKeys($listResponse->json());

        $this->deleteJson('/api/v2/admin/coupons/'.$couponId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_admin_coupon_campaign_create_update_list_and_delete_use_v2_resources(): void
    {
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::PRODUCT_LIST,
            AdminPermissions::PRODUCT_MANAGE,
        ]));

        $suffix = bin2hex(random_bytes(4));
        $createResponse = $this->postJson('/api/v2/admin/coupon-campaigns', $this->campaignPayload('V2 活动 '.$suffix))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '活动已创建')
            ->assertJsonPath('data.campaign.name', 'V2 活动 '.$suffix);

        $campaignId = (int) $createResponse->json('data.campaign.id');
        $this->assertSame($this->campaignWhitelist(), array_keys($createResponse->json('data.campaign')));
        $this->assertNoSensitiveKeys($createResponse->json());

        $this->putJson('/api/v2/admin/coupon-campaigns/'.$campaignId, $this->campaignPayload('V2 活动更新 '.$suffix, 30))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.campaign.name', 'V2 活动更新 '.$suffix)
            ->assertJsonPath('data.campaign.issue_quantity', 30);

        $listResponse = $this->getJson('/api/v2/admin/coupon-campaigns?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $campaignId);

        $this->assertSame($this->campaignWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));
        $this->assertNoSensitiveKeys($listResponse->json());

        $summaryResponse = $this->getJson('/api/v2/admin/coupon-campaigns/summary?keyword='.$suffix)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame($this->campaignSummaryWhitelist(), array_keys($summaryResponse->json('data')));
        $this->assertNoSensitiveKeys($summaryResponse->json());

        $this->deleteJson('/api/v2/admin/coupon-campaigns/'.$campaignId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_admin_coupon_campaign_cannot_update_after_generating_coupon_batch(): void
    {
        Sanctum::actingAs($this->createAdmin([
            AdminPermissions::PRODUCT_LIST,
            AdminPermissions::PRODUCT_MANAGE,
        ]));

        $suffix = bin2hex(random_bytes(4));
        $createResponse = $this->postJson('/api/v2/admin/coupon-campaigns', $this->campaignPayload('V2 已生成活动 '.$suffix))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $campaignId = (int) $createResponse->json('data.campaign.id');

        $this->postJson('/api/v2/admin/coupon-campaigns/'.$campaignId.'/tasks', [
            'type' => 'trigger',
            'payload' => [],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->putJson('/api/v2/admin/coupon-campaigns/'.$campaignId, $this->campaignPayload('V2 已生成活动更新 '.$suffix, 30))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '活动已生成优惠券批次，不允许修改');

        $this->getJson('/api/v2/admin/coupon-campaigns?'.http_build_query([
            'keyword' => $suffix,
            'page' => 1,
            'page_size' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('data.list.0.name', 'V2 已生成活动 '.$suffix)
            ->assertJsonPath('data.list.0.issue_quantity', 20)
            ->assertJsonPath('data.list.0.can_update', false)
            ->assertJsonPath('data.list.0.can_delete', false)
            ->assertJsonPath('data.list.0.lock_reason', '活动已生成优惠券批次，不允许修改或删除');
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-coupon-management-'.$suffix,
            'label' => 'V2 Coupon Management',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-coupon-management-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Coupon Management',
            'email' => 'v2-coupon-management-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function couponPayload(string $name, int $discountValue = 10): array
    {
        return [
            'name' => $name,
            'description' => 'v2 coupon description',
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => $discountValue,
            'min_amount' => 0,
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'user_ids' => [],
            'total_usage_limit' => null,
            'per_user_limit' => null,
            'status' => 1,
            'sort_order' => 0,
            'starts_at' => null,
            'expires_at' => null,
            'remark' => 'v2 coupon remark',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayload(string $name, int $issueQuantity = 20): array
    {
        return [
            'name' => $name,
            'description' => 'v2 campaign description',
            'weekdays' => [1, 3, 5],
            'trigger_time' => '18:00:00',
            'issue_quantity' => $issueQuantity,
            'valid_duration_hours' => 48,
            'discount_type' => 'percentage',
            'discount_scope' => 'first_month',
            'discount_value' => 80,
            'min_amount' => 0,
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'per_user_limit' => 1,
            'status' => 1,
            'sort_order' => 0,
            'remark' => 'v2 campaign remark',
        ];
    }

    /**
     * @return list<string>
     */
    private function couponSummaryWhitelist(): array
    {
        return [
            'total',
            'active',
            'expired',
            'disabled',
            'public_total',
            'private_total',
            'total_used',
            'enabled',
        ];
    }

    /**
     * @return list<string>
     */
    private function couponWhitelist(): array
    {
        return [
            'id',
            'coupon_campaign_id',
            'coupon_campaign_name',
            'name',
            'description',
            'distribution_type',
            'distribution_type_label',
            'discount_scope',
            'discount_scope_label',
            'discount_type',
            'discount_type_label',
            'discount_value',
            'discount_value_raw',
            'discount_label',
            'min_amount',
            'min_amount_raw',
            'max_discount_amount',
            'max_discount_amount_raw',
            'billing_cycles',
            'billing_cycle_text',
            'product_ids',
            'product_names',
            'product_scope_text',
            'first_order_only',
            'total_usage_limit',
            'per_user_limit',
            'used_count',
            'recipient_count',
            'user_ids',
            'remaining_stock',
            'status',
            'status_label',
            'display_status',
            'display_status_label',
            'display_status_reason',
            'sort_order',
            'starts_at',
            'expires_at',
            'validity_text',
            'remark',
            'operator',
            'trace_id',
            'created_at',
            'updated_at',
            'can_update',
            'can_delete',
            'lock_reason',
            'locked_fields',
            'delete_reason',
        ];
    }

    /**
     * @return list<string>
     */
    private function campaignSummaryWhitelist(): array
    {
        return [
            'total',
            'active',
            'disabled',
            'generated_today',
        ];
    }

    /**
     * @return list<string>
     */
    private function campaignWhitelist(): array
    {
        return [
            'id',
            'name',
            'description',
            'weekdays',
            'weekdays_text',
            'trigger_time',
            'trigger_time_text',
            'schedule_text',
            'issue_quantity',
            'valid_duration_hours',
            'discount_scope',
            'discount_scope_label',
            'discount_type',
            'discount_type_label',
            'discount_value',
            'discount_value_raw',
            'discount_label',
            'min_amount',
            'min_amount_raw',
            'max_discount_amount',
            'max_discount_amount_raw',
            'billing_cycles',
            'billing_cycle_text',
            'product_ids',
            'product_scope_text',
            'first_order_only',
            'per_user_limit',
            'status',
            'status_label',
            'display_status',
            'display_status_label',
            'sort_order',
            'generated_coupon_count',
            'next_run_at',
            'last_dispatched_at',
            'last_coupon_id',
            'last_coupon_name',
            'last_coupon_code',
            'remark',
            'operator',
            'trace_id',
            'created_at',
            'updated_at',
            'can_update',
            'can_delete',
            'lock_reason',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains(strtolower($key), [
                    'password',
                    'secret',
                    'api_key',
                    'raw_response',
                    'third_party_response',
                ]);
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
