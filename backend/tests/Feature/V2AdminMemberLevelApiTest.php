<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MemberLevel;
use App\Models\Role;
use App\Models\User;
use App\Models\UserReferral;
use App\Support\AdminPermissions;
use App\Support\CacheKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminMemberLevelApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_member_level_list_requires_permission_rejects_legacy_pagination_and_returns_whitelist(): void
    {
        $this->resetMemberLevelReferences();
        MemberLevel::query()->delete();
        $level = MemberLevel::query()->create([
            'name' => 'V2 List Level',
            'code' => 'v2_list_level',
            'sales_amount_min' => '0.00',
            'sales_amount_max' => '100.00',
            'reward_rate' => '5.00',
            'status' => 1,
            'sort_order' => 1,
            'remark' => 'list test',
        ]);
        $this->forgetMemberLevelCaches();

        $this->getJson('/api/v2/admin/member-levels')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW], 'member-level-list-forbidden'));

        $this->getJson('/api/v2/admin/member-levels')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::MEMBER_LEVEL_LIST], 'member-level-list-allowed'));

        $this->getJson('/api/v2/admin/member-levels?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/member-levels?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/member-levels')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $level->id);

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame(['list'], array_keys($payload['data']));
        $this->assertSame($this->memberLevelFields(), array_keys($payload['data']['list'][0]));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_member_level_write_endpoints_require_permission_and_return_whitelisted_payloads(): void
    {
        $this->resetMemberLevelReferences();
        MemberLevel::query()->delete();
        $this->forgetMemberLevelCaches();

        $suffix = bin2hex(random_bytes(4));
        $createPayload = [
            'name' => 'V2 Create Level '.$suffix,
            'code' => 'v2_create_'.$suffix,
            'sales_amount_min' => '0.00',
            'sales_amount_max' => '100.00',
            'reward_rate' => '5.50',
            'status' => 1,
            'sort_order' => 1,
            'remark' => 'create test',
        ];

        $this->postJson('/api/v2/admin/member-levels', $createPayload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::MEMBER_LEVEL_LIST], 'member-level-write-forbidden'));

        $this->postJson('/api/v2/admin/member-levels', $createPayload)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::MEMBER_LEVEL_MANAGE], 'member-level-write-allowed'));

        $this->postJson('/api/v2/admin/member-levels', $createPayload + ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $createResponse = $this->postJson('/api/v2/admin/member-levels', $createPayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '等级创建成功')
            ->assertJsonPath('data.code', $createPayload['code'])
            ->assertJsonPath('data.reward_rate', '5.50');

        $levelId = (int) $createResponse->json('data.id');
        $this->assertSame($this->memberLevelFields(), array_keys($createResponse->json('data')));
        $this->assertNoSensitiveKeys($createResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $createResponse->getContent()));

        $updatePayload = [
            'name' => 'V2 Updated Level '.$suffix,
            'code' => 'v2_updated_'.$suffix,
            'sales_amount_min' => '0.00',
            'sales_amount_max' => '120.00',
            'reward_rate' => '6.25',
            'status' => 1,
            'sort_order' => 2,
            'remark' => 'update test',
        ];

        $this->putJson('/api/v2/admin/member-levels/'.$levelId, $updatePayload + ['pageSize' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $updateResponse = $this->putJson('/api/v2/admin/member-levels/'.$levelId, $updatePayload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '等级更新成功')
            ->assertJsonPath('data.id', $levelId)
            ->assertJsonPath('data.name', $updatePayload['name'])
            ->assertJsonPath('data.reward_rate', '6.25');

        $this->assertSame($this->memberLevelFields(), array_keys($updateResponse->json('data')));
        $this->assertNoSensitiveKeys($updateResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $updateResponse->getContent()));

        $deleteLevel = MemberLevel::query()->create([
            'name' => 'V2 Delete Level '.$suffix,
            'code' => 'v2_delete_'.$suffix,
            'sales_amount_min' => '1000000.00',
            'sales_amount_max' => '1000100.00',
            'reward_rate' => '1.00',
            'status' => 1,
            'sort_order' => 99,
            'remark' => 'delete test',
        ]);
        $this->resetMemberLevelReferences((int) $deleteLevel->id);

        $this->deleteJson('/api/v2/admin/member-levels/'.$deleteLevel->id, ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $deleteResponse = $this->deleteJson('/api/v2/admin/member-levels/'.$deleteLevel->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '等级删除成功')
            ->assertJsonPath('data', null);

        $this->assertNoSensitiveKeys($deleteResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $deleteResponse->getContent()));
        $this->assertDatabaseMissing('member_levels', ['id' => $deleteLevel->id]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions, string $prefix): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-'.$prefix.'-'.$suffix,
            'label' => 'V2 '.$prefix,
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-'.$prefix.'-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Member Level',
            'email' => 'v2-'.$prefix.'-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function memberLevelFields(): array
    {
        return [
            'id',
            'name',
            'code',
            'sales_amount_min',
            'sales_amount_max',
            'reward_rate',
            'status',
            'sort_order',
            'remark',
            'created_at',
            'updated_at',
        ];
    }

    private function forgetMemberLevelCaches(): void
    {
        Cache::forget(CacheKey::memberLevels(false));
        Cache::forget(CacheKey::memberLevels(true));
    }

    private function resetMemberLevelReferences(?int $levelId = null): void
    {
        User::query()
            ->when(
                $levelId === null,
                fn ($query) => $query->whereNotNull('member_level_id'),
                fn ($query) => $query->where('member_level_id', $levelId),
            )
            ->update(['member_level_id' => null]);

        if (Schema::hasTable('user_referrals')) {
            UserReferral::query()
                ->when(
                    $levelId === null,
                    fn ($query) => $query->whereNotNull('member_level_id'),
                    fn ($query) => $query->where('member_level_id', $levelId),
                )
                ->update(['member_level_id' => null]);
        }
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
