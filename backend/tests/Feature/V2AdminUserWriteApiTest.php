<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Finance\PaymentService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminUserWriteApiTest extends TestCase
{
    public function test_user_write_endpoints_require_manage_and_return_whitelisted_user(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $this->postJson('/api/v2/admin/users', $this->createPayload($suffix))
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_LIST]));

        $this->postJson('/api/v2/admin/users', $this->createPayload($suffix))
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));

        $this->postJson('/api/v2/admin/users', array_merge($this->createPayload($suffix), ['per_page' => 20]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/admin/users', array_merge($this->createPayload($suffix), ['phone' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['phone']]]);

        $createResponse = $this->postJson('/api/v2/admin/users', $this->createPayload($suffix))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.email', 'v***@example.com')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.secret')
            ->assertJsonMissingPath('data.api_key');

        $userId = (int) $createResponse->json('data.id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'v2-user-write-'.$suffix.'@example.com',
        ]);

        $this->assertSame($this->userListFields(), array_keys($createResponse->json('data')));
        $this->assertNoSensitiveKeys($createResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $createResponse->getContent()));

        $this->putJson('/api/v2/admin/users/'.$userId, [
            'nickname' => 'Updated '.$suffix,
            'pageSize' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->putJson('/api/v2/admin/users/'.$userId, [
            'phone' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['phone']]]);

        $updatedPhone = '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $updateResponse = $this->putJson('/api/v2/admin/users/'.$userId, [
            'nickname' => 'Updated '.$suffix,
            'phone' => $updatedPhone,
            'status' => 1,
            'credit_limit' => '30.00',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.nickname', 'Updated '.$suffix)
            ->assertJsonMissingPath('data.password');

        $this->assertSame($this->userListFields(), array_keys($updateResponse->json('data')));
        $this->assertNoSensitiveKeys($updateResponse->json());
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'phone' => $updatedPhone,
        ]);

        $this->deleteJson('/api/v2/admin/users/'.$userId.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->deleteJson('/api/v2/admin/users/'.$userId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_recharge_login_as_and_os_options_are_v2_actions(): void
    {
        $user = $this->createUser();

        $this->mock(PaymentService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('adjustBalance')
                ->once()
                ->withArgs(fn (User $actualUser, float $amount, string $remark, array $context): bool => (int) $actualUser->id === (int) $user->id
                    && $amount === 25.5
                    && $remark === '测试调整'
                    && ($context['operator_name'] ?? '') !== '')
                ->andReturn([
                    'invoice' => (object) ['id' => 101],
                    'transaction' => (object) ['id' => 202],
                    'recharge_record' => (object) ['id' => 303],
                ]);
        });

        $this->mock(AuthService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('issueAdminLoginAsCode')
                ->once()
                ->withArgs(fn (User $actualUser, array $context): bool => (int) $actualUser->id === (int) $user->id
                    && ($context['admin_id'] ?? 0) > 0)
                ->andReturn([
                    'login_code' => 'login-as-code',
                    'expires_in' => 120,
                    'target_url' => 'https://console.example.test/client/login-as',
                    'token' => 'must-not-leak',
                    'user' => [
                        'id' => (int) $user->id,
                        'email' => (string) $user->email,
                        'nickname' => (string) $user->nickname,
                        'password' => 'must-not-leak',
                    ],
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));

        $this->postJson('/api/v2/admin/users/'.$user->id.'/recharges', [
            'amount' => 25.5,
            'remark' => '测试调整',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->postJson('/api/v2/admin/users/'.$user->id.'/login-as')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->getJson('/api/v2/admin/os-options?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $osResponse = $this->getJson('/api/v2/admin/os-options')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.groups.0.label', 'CentOS');

        $this->assertSame(['groups'], array_keys($osResponse->json('data')));
        $this->assertNoSensitiveKeys($osResponse->json());

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_RECHARGE]));

        $this->postJson('/api/v2/admin/users/'.$user->id.'/recharges', [
            'amount' => 25.5,
            'remark' => '测试调整',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $rechargeResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/recharges', [
            'amount' => 25.5,
            'remark' => '测试调整',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.adjustment.amount', '25.50')
            ->assertJsonPath('data.detail.documents.invoice_id', 101)
            ->assertJsonPath('data.detail.documents.account_transaction_id', 202)
            ->assertJsonPath('data.detail.documents.recharge_record_id', 303);

        $this->assertActionResponse($rechargeResponse->json());

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_LOGIN_AS]));

        $this->postJson('/api/v2/admin/users/'.$user->id.'/login-as', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $loginAsResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/login-as')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.login_code', 'login-as-code')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password');

        $this->assertSame(['login_code', 'expires_in', 'target_url', 'user'], array_keys($loginAsResponse->json('data')));
        $this->assertNoSensitiveKeys($loginAsResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $loginAsResponse->getContent()));
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(string $suffix): array
    {
        return [
            'email' => 'v2-user-write-'.$suffix.'@example.com',
            'password' => 'Client@123456',
            'nickname' => 'V2 User Write '.$suffix,
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'credit_limit' => '20.00',
        ];
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'v2-user-action-'.$suffix.'@example.com',
            'password' => 'Client@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'nickname' => 'V2 User Action '.$suffix,
            'status' => 1,
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-user-write-'.$suffix,
            'label' => 'V2 User Write',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-user-write-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 User Write',
            'email' => 'v2-user-write-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function userListFields(): array
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

    private function assertActionResponse(array $payload): void
    {
        $this->assertSame(['id', 'status', 'message', 'detail'], array_keys($payload['data']));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
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
