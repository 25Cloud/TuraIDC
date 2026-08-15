<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MemberLevel;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMemberLevelControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_and_update_member_level_with_form_request_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->actingAsMemberLevelManager($suffix);
        MemberLevel::query()->delete();

        $createResponse = $this->postJson('/api/v2/admin/member-levels', [
            'name' => '治理等级 '.$suffix,
            'code' => 'govern_member_'.$suffix,
            'sales_amount_min' => '987654.00',
            'sales_amount_max' => '987655.00',
            'reward_rate' => '5.50',
            'status' => 1,
            'sort_order' => 10,
            'remark' => '创建测试',
        ]);

        $createResponse
            ->assertOk()
            ->assertJsonPath('data.code', 'govern_member_'.$suffix)
            ->assertJsonPath('data.reward_rate', '5.50');

        $levelId = (int) $createResponse->json('data.id');

        $updateResponse = $this->putJson('/api/v2/admin/member-levels/'.$levelId, [
            'name' => '治理等级更新 '.$suffix,
            'code' => 'govern_member_'.$suffix,
            'sales_amount_min' => '987654.00',
            'sales_amount_max' => '987656.00',
            'reward_rate' => '6.25',
            'status' => 1,
            'sort_order' => 11,
            'remark' => '更新测试',
        ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('data.name', '治理等级更新 '.$suffix)
            ->assertJsonPath('data.reward_rate', '6.25');

        $this->assertDatabaseHas((new MemberLevel)->getTable(), [
            'id' => $levelId,
            'code' => 'govern_member_'.$suffix,
            'reward_rate' => '6.25',
        ]);
    }

    private function actingAsMemberLevelManager(string $suffix): void
    {
        $role = Role::query()->create([
            'name' => 'member-level-'.$suffix,
            'label' => 'Member Level',
            'permissions' => [AdminPermissions::MEMBER_LEVEL_MANAGE],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'member-level-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Member Level',
            'email' => 'member-level-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);
    }
}
