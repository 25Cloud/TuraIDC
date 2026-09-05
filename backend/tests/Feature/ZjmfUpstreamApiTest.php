<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZjmfUpstream\DcimService;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 上游服务商 API（被魔方财务对接）认证与按钮分发回归测试。
 *
 * 重点回归：登录账号条件必须与鉴权中间件一致（status=1 + api_open=1）。
 * 登录放行而中间件拒绝会形成「登录成功 → 每次请求 405 → 魔方财务强制重登 →
 * 再 405」的死循环，下游表现为对接持续 405。
 */
class ZjmfUpstreamApiTest extends TestCase
{
    #[Test]
    public function login_issues_jwt_for_enabled_api_account(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonStructure(['jwt']);
    }

    #[Test]
    public function login_rejects_disabled_account_to_avoid_permanent_405_loop(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 0, 'api_open' => 1]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function login_rejects_account_without_api_access(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 0]);

        $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    #[Test]
    public function business_request_with_invalid_jwt_returns_405(): void
    {
        $this->getJson('/api/v2/zjmf/user_info', ['Authorization' => 'Bearer invalid.token.value'])
            ->assertOk()
            ->assertJsonPath('status', 405);
    }

    #[Test]
    public function business_request_with_valid_jwt_passes_authentication(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);

        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $this->assertIsString($jwt) && $jwt !== '';

        $this->getJson('/api/v2/zjmf/user_info', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('user.id', (int) $user->id);
    }

    #[Test]
    public function provision_button_dispatches_power_actions(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $dcim = $this->createMock(DcimService::class);
        $dcim->method('on')->willReturn(['status' => 200, 'msg' => '操作成功']);
        $this->swap(DcimService::class, $dcim);

        $this->postJson('/api/v2/zjmf/provision/button', ['id' => 321, 'func' => 'on'], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 200);
        $dcim->expects($this->never())->method('off');
    }

    #[Test]
    public function provision_button_rejects_unknown_func(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createApiUser($suffix, ['status' => 1, 'api_open' => 1]);
        $jwt = $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');

        $this->postJson('/api/v2/zjmf/provision/button', ['id' => 321, 'func' => 'flow'], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('status', 400);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createApiUser(string $suffix, array $overrides): User
    {
        $user = User::query()->create([
            'email' => 'zjmfapi-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Zjmf Upstream Api',
        ]);

        $user->forceFill([
            'api_open' => (int) ($overrides['api_open'] ?? 1),
            'api_username' => 'zjmfapi_'.$suffix,
            'api_password' => Hash::make('ZjmfApi@123456'),
            'status' => (int) ($overrides['status'] ?? 1),
        ])->save();

        return $user->refresh();
    }
}
