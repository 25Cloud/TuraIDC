<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AccountPolicyService;
use App\Services\System\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 账户策略：实名 / 手机号 / 邮箱 是否强制。
 *
 * 三件事必须一起成立，缺一个都会让运营踩坑：
 * 1. 开关全关时行为与升级前完全一致（存量站点不会被上线动作拦人）；
 * 2. 开关打开后，会动钱的入口（下单 / 支付 / 充值 / 提现）返回 40301 并点名缺哪几项，
 *    而只读接口、登录、控制台首屏一律不受影响 —— 老用户不会只剩一个报错页；
 * 3. 管理端保存后立刻生效：settings 有分组缓存，保存路径必须把它清掉。
 */
class AccountPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private const CACHE_KEY = 'settings:group:account';

    public function test_reads_and_console_bootstrap_are_never_blocked_while_writes_are(): void
    {
        $this->applyPolicy(requireVerification: true, requirePhone: true, requireEmail: true);

        $user = $this->makeClient(['is_verified' => 0, 'verification_status' => 0, 'phone' => '', 'email' => '']);
        Sanctum::actingAs($user);

        // 首个请求（控制台拿用户信息）必须 200：老用户被收紧后照常进得去，
        // 只是拿到「还缺哪几项」，由控制台自己引导。
        $this->getJson('/api/v2/client/auth/info')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.account_policy.require_verification', true)
            ->assertJsonPath('data.account_policy.require_phone', true)
            ->assertJsonPath('data.account_policy.require_email', true)
            ->assertJsonPath('data.account_policy.missing_items', ['verification', 'phone', 'email'])
            ->assertJsonPath('data.account_policy.message', '继续操作前请先完成实名认证、绑定手机号、绑定邮箱');

        // 只读列表不受影响
        $this->getJson('/api/v2/client/invoices')->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/v2/client/referral/overview')->assertOk()->assertJsonPath('code', 0);

        // 写入口逐个拦下，并且要说清缺什么
        $this->assertPolicyBlocked(
            $this->postJson('/api/v2/client/referral/withdrawals', ['amount' => 1, 'method' => 'balance']),
            '提现前请先完成实名认证、绑定手机号、绑定邮箱'
        );
        $this->assertPolicyBlocked(
            $this->postJson('/api/v2/client/recharge', ['amount' => 10]),
            '充值前请先完成实名认证、绑定手机号、绑定邮箱'
        );
        $this->assertPolicyBlocked(
            $this->postJson('/api/v2/client/invoices', [
                'product_id' => 1,
                'billing_cycle' => 'monthly',
                'quote_token' => str_repeat('a', 24),
            ]),
            '下单前请先完成实名认证、绑定手机号、绑定邮箱'
        );
    }

    public function test_all_switches_off_keeps_existing_behaviour(): void
    {
        $this->applyPolicy(requireVerification: false, requirePhone: false, requireEmail: false);

        $user = $this->makeClient(['is_verified' => 0, 'verification_status' => 0, 'phone' => '', 'email' => '']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/auth/info')
            ->assertOk()
            ->assertJsonPath('data.account_policy.missing_items', [])
            ->assertJsonPath('data.account_policy.message', '');

        // 提现只在业务层被拦（金额低于起提门槛），不是策略拦的
        $this->postJson('/api/v2/client/referral/withdrawals', ['amount' => 1, 'method' => 'balance'])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        // 下单：策略放行后才会走到报价/商品校验，错误码不是 40301
        $response = $this->postJson('/api/v2/client/invoices', [
            'product_id' => 1,
            'billing_cycle' => 'monthly',
            'quote_token' => str_repeat('a', 24),
        ]);
        $this->assertNotSame(40301, (int) $response->json('code'));
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_single_switch_only_blocks_its_own_item(): void
    {
        // 只开手机号：已实名、有邮箱的老用户照样可以走业务逻辑
        $this->applyPolicy(requireVerification: false, requirePhone: true, requireEmail: false);

        $bound = $this->makeClient([
            'is_verified' => 1,
            'verification_status' => 2,
            'phone' => '13800000000',
            'email' => 'bound@example.com',
        ]);
        Sanctum::actingAs($bound);

        $this->getJson('/api/v2/client/auth/info')
            ->assertOk()
            ->assertJsonPath('data.account_policy.missing_items', []);

        $this->postJson('/api/v2/client/referral/withdrawals', ['amount' => 1, 'method' => 'balance'])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        // 同一开关下，缺手机号的用户只被告知缺手机号
        $unbound = $this->makeClient([
            'is_verified' => 1,
            'verification_status' => 2,
            'phone' => '',
            'email' => 'unbound@example.com',
        ]);
        Sanctum::actingAs($unbound);

        $this->getJson('/api/v2/client/auth/info')
            ->assertOk()
            ->assertJsonPath('data.account_policy.missing_items', ['phone']);

        $this->assertPolicyBlocked(
            $this->postJson('/api/v2/client/referral/withdrawals', ['amount' => 1, 'method' => 'balance']),
            '提现前请先完成绑定手机号'
        );
    }

    public function test_policy_change_takes_effect_immediately_and_clears_group_cache(): void
    {
        $this->applyPolicy(requireVerification: false, requirePhone: false, requireEmail: false);

        $user = $this->makeClient(['is_verified' => 0, 'verification_status' => 0, 'phone' => '', 'email' => '']);
        Sanctum::actingAs($user);

        $policy = app(AccountPolicyService::class);
        $this->assertFalse($policy->requirements()['require_verification']);
        // 读一次就已经把分组缓存写进去了（settings 的 CACHE_TTL_SECONDS = 600）
        $this->assertTrue(Cache::has(self::CACHE_KEY));

        // 管理端保存走的就是这条路：SettingController::update → saveGroupSettings
        app(SettingService::class)->saveGroupSettings('account', [
            'require_verification' => true,
            'require_phone' => false,
            'require_email' => false,
        ]);

        // 保存必须清掉分组缓存，否则要等 TTL 过期才生效，运营会以为开关没反应
        $this->assertFalse(Cache::has(self::CACHE_KEY));
        $this->assertTrue($policy->requirements()['require_verification']);

        $this->assertPolicyBlocked(
            $this->postJson('/api/v2/client/referral/withdrawals', ['amount' => 1, 'method' => 'balance']),
            '提现前请先完成实名认证'
        );
    }

    public function test_settings_group_exposes_three_switches_and_rejects_unknown_keys(): void
    {
        // 管理端 GET /settings?group=account 拿到的就是这三个键（默认关闭时也要回显）
        $keys = collect(app(SettingService::class)->getGroupSettings('account')->all())
            ->pluck('key')
            ->values()
            ->all();

        $this->assertSame(['require_verification', 'require_phone', 'require_email'], $keys);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('账户策略配置包含不受支持的字段');

        app(SettingService::class)->saveGroupSettings('account', ['require_verification' => true, 'force_all' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeClient(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => 'account-policy-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13800000000',
            'status' => 1,
            'nickname' => 'Account Policy',
            'real_name' => '',
            'id_card' => '',
            'is_verified' => 1,
            'verification_status' => 2,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ], $overrides));
    }

    private function applyPolicy(bool $requireVerification, bool $requirePhone, bool $requireEmail): void
    {
        Setting::setValues(AccountPolicyService::GROUP, [
            'require_verification' => $requireVerification ? '1' : '0',
            'require_phone' => $requirePhone ? '1' : '0',
            'require_email' => $requireEmail ? '1' : '0',
        ]);
    }

    private function assertPolicyBlocked(TestResponse $response, string $expectedMessage): void
    {
        $response->assertStatus(403)
            ->assertJsonPath('code', 40301)
            ->assertJsonPath('message', $expectedMessage);
    }
}
