<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminUserReadApiTest extends TestCase
{
    public function test_user_list_requires_permission_and_returns_whitelisted_pagination(): void
    {
        $user = $this->createClientUser('list');

        $this->getJson('/api/v2/admin/users?user_id='.$user->id.'&page_size=1')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/users?user_id='.$user->id.'&page_size=1')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_LIST]));

        $this->getJson('/api/v2/admin/users?user_id='.$user->id.'&per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/users?user_id='.$user->id.'&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/users?user_id='.$user->id.'&page_size=1')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.list.0.id', $user->id)
            ->assertJsonMissingPath('data.list.0.password')
            ->assertJsonMissingPath('data.list.0.id_card')
            ->assertJsonMissingPath('data.list.0.verification_certify_id');

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($payload['data']));
        $this->assertSame($this->listFields(), array_keys($payload['data']['list'][0]));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_user_detail_requires_permission_and_returns_whitelisted_payload(): void
    {
        $user = $this->createClientUser('detail');

        $this->getJson('/api/v2/admin/users/'.$user->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/users/'.$user->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));

        $this->getJson('/api/v2/admin/users/'.$user->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.referral_code', $user->referral_code)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.id_card')
            ->assertJsonMissingPath('data.user.verification_certify_id')
            ->assertJsonMissingPath('data.user.secret')
            ->assertJsonMissingPath('data.user.api_key');

        $payload = $response->json();
        $this->assertSame(['user', 'stats', 'referral'], array_keys($payload['data']));
        $this->assertSame($this->detailUserFields(), array_keys($payload['data']['user']));
        $this->assertSame($this->statsFields(), array_keys($payload['data']['stats']));
        $this->assertSame($this->referralFields(), array_keys($payload['data']['referral']));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    private function createClientUser(string $prefix): User
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));

        return User::query()->create([
            'email' => 'v2-admin-user-'.$prefix.'-'.$suffix.'@example.com',
            'password' => 'Client@123456',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'nickname' => 'V2 User '.$prefix,
            'real_name' => '测试用户',
            'id_card' => '110101199001011234',
            'verification_status' => 2,
            'verification_certify_id' => 'CERT-'.$suffix,
            'is_verified' => 1,
            'status' => 1,
            'referral_code' => strtoupper(substr($suffix, 0, 6)),
            'alipay_real_name' => '测试用户',
            'alipay_account' => substr('ali'.$suffix, 0, 16),
            'last_login_ip' => '127.0.0.1',
            'last_login_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-user-read-'.$suffix,
            'label' => 'V2 User Read',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-user-read-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 User Read',
            'email' => 'v2-user-read-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listFields(): array
    {
        return [
            'id',
            'email',
            'phone',
            'nickname',
            'display_name',
            'company',
            'qq',
            'member_level_id',
            'verification_status',
            'verification_status_label',
            'real_name',
            'cash_balance',
            'credit_limit',
            'referral_frozen_balance',
            'referral_available_balance',
            'referral_pending_withdrawal_balance',
            'referral_withdrawn_balance',
            'status',
            'is_verified',
            'opened_product_count',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function detailUserFields(): array
    {
        return [
            'id',
            'email',
            'nickname',
            'display_name',
            'phone',
            'company',
            'qq',
            'admin_note',
            'referral_code',
            'referrer_user_id',
            'member_level_id',
            'total_sales_amount',
            'member_level',
            'cash_balance',
            'credit_limit',
            'referral_frozen_balance',
            'referral_available_balance',
            'referral_pending_withdrawal_balance',
            'referral_withdrawn_balance',
            'active_services_count',
            'status',
            'is_verified',
            'verification_status',
            'verification_status_label',
            'real_name',
            'id_card_masked',
            'referred_at',
            'alipay_real_name',
            'alipay_account',
            'last_login_at',
            'last_login_ip',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function statsFields(): array
    {
        return [
            'service_active',
            'service_total',
            'order_total',
            'order_pending',
            'total_income',
            'total_expense',
            'unpaid_amount',
            'ticket_open',
            'ticket_closed',
            'ticket_total',
            'invoice_unpaid',
            'invoice_paid',
            'direct_referral_count',
            'rewarded_orders_count',
            'total_referral_reward',
        ];
    }

    /**
     * @return list<string>
     */
    private function referralFields(): array
    {
        return [
            'referral_code',
            'referrer_user_id',
            'member_level',
            'total_sales_amount',
            'referral_frozen_amount',
            'referral_available_amount',
            'referral_withdrawing_amount',
            'referral_withdrawn_amount',
            'recent_referrals',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token', 'verification_certify_id'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
