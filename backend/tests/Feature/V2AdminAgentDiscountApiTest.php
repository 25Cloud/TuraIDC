<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\ProductDiscountGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminAgentDiscountApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_agent_discount_lists_require_list_permission(): void
    {
        $this->getJson('/api/v2/admin/agent-groups')->assertUnauthorized();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/agent-groups')->assertForbidden();
        $this->getJson('/api/v2/admin/product-discount-groups')->assertForbidden();
        $this->getJson('/api/v2/admin/agent-group-discounts')->assertForbidden();
    }

    public function test_agent_discount_lists_return_groups_and_matrix_with_list_permission(): void
    {
        $agentGroup = AgentGroup::query()->create([
            'name' => '代理组 A', 'code' => 'agent_a', 'status' => 1, 'sort_order' => 1,
        ]);
        $productGroup = ProductDiscountGroup::query()->create([
            'name' => '商品折扣组 A', 'code' => 'product_a', 'min_discount_rate' => '80.00',
            'cost_rate' => '50.00', 'status' => 1, 'sort_order' => 1,
        ]);
        AgentGroupDiscount::query()->create([
            'agent_group_id' => $agentGroup->id,
            'product_discount_group_id' => $productGroup->id,
            'discount_rate' => '85.00',
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::AGENT_DISCOUNT_LIST]));

        $this->getJson('/api/v2/admin/agent-groups')
            ->assertOk()
            ->assertJsonPath('data.list.0.code', 'agent_a');
        $this->getJson('/api/v2/admin/product-discount-groups')
            ->assertOk()
            ->assertJsonPath('data.list.0.min_discount_rate', '80.00');
        $this->getJson('/api/v2/admin/agent-group-discounts')
            ->assertOk()
            ->assertJsonPath('data.rows.0.discounts.0.discount_rate', '85.00');
    }

    public function test_agent_discount_management_supports_crud_and_validates_matrix_minimum(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::AGENT_DISCOUNT_MANAGE]));

        $agentResponse = $this->postJson('/api/v2/admin/agent-groups', [
            'name' => '代理组 B', 'code' => 'agent_b', 'status' => 1, 'sort_order' => 2,
        ])->assertOk()->assertJsonPath('data.code', 'agent_b');
        $agentId = (int) $agentResponse->json('data.id');

        $productResponse = $this->postJson('/api/v2/admin/product-discount-groups', [
            'name' => '商品折扣组 B', 'code' => 'product_b', 'min_discount_rate' => '75.00',
            'cost_rate' => '40.00', 'status' => 1, 'sort_order' => 2,
        ])->assertOk()->assertJsonPath('data.code', 'product_b');
        $productId = (int) $productResponse->json('data.id');

        $this->putJson('/api/v2/admin/agent-group-discounts', [
            'items' => [[
                'agent_group_id' => $agentId,
                'product_discount_group_id' => $productId,
                'discount_rate' => '70.00',
            ]],
        ])->assertUnprocessable();

        $this->putJson('/api/v2/admin/agent-group-discounts', [
            'items' => [[
                'agent_group_id' => $agentId,
                'product_discount_group_id' => $productId,
                'discount_rate' => '80.00',
            ]],
        ])->assertOk()->assertJsonPath('data.0.discount_rate', '80.00');

        $this->putJson('/api/v2/admin/agent-groups/'.$agentId, [
            'name' => '代理组 B2', 'code' => 'agent_b2', 'status' => 1, 'sort_order' => 3,
        ])->assertOk()->assertJsonPath('data.name', '代理组 B2');

        User::query()->create(['email' => 'agent-bound@example.com', 'password' => 'Temp@123456', 'agent_group_id' => $agentId]);
        $this->deleteJson('/api/v2/admin/agent-groups/'.$agentId)->assertUnprocessable();
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'agent-discount-'.$suffix,
            'label' => '代理折扣测试',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'agent-discount-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => $role->id,
            'nickname' => '代理折扣测试',
            'email' => 'agent-discount-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
