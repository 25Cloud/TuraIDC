<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\User\UserService;
use Tests\TestCase;

/**
 * 会话吊销与代登录凭证的 UA 绑定。
 *
 * 两条都属于「防线看起来在、实际不生效」的类型，读代码容易一眼滑过去，故单独钉住。
 */
class AdminUserSessionRevocationTest extends TestCase
{
    /**
     * 管理员改用户密码必须吊销该用户已签发的全部 token。
     *
     * 这条通道是处置盗号的主入口：改了密码但旧 token 仍然有效，管理员与用户都会以为
     * 访问已切断，实际攻击者在闲置 3h（sanctum.idle_timeout）/ 签发满 24h
     * （sanctum.expiration）之前照样能操作账号。
     */
    public function test_admin_password_update_revokes_all_user_tokens(): void
    {
        $user = $this->makeClientUser();

        $user->createToken('client-token');
        $user->createToken('client-token');
        $this->assertSame(2, $user->tokens()->count(), '前置条件：该用户应有两个有效 token');

        app(UserService::class)->update($user, ['password' => 'NewSecret@123456']);

        $this->assertSame(
            0,
            $user->tokens()->count(),
            '管理员改密后必须吊销全部已签发 token，否则被盗 token 仍可继续使用'
        );
    }

    /**
     * 不改密码的普通编辑不得误伤会话。
     *
     * 吊销必须严格绑定「密码确实变更」，管理员改个昵称就把用户踢下线同样是回归。
     */
    public function test_admin_profile_update_without_password_keeps_tokens(): void
    {
        $user = $this->makeClientUser();
        $user->createToken('client-token');

        app(UserService::class)->update($user, ['nickname' => '改个昵称']);

        $this->assertSame(1, $user->tokens()->count(), '未改密码时不应吊销会话');
    }

    /**
     * 代登录交换：签发时记录了 UA，交换侧不带 UA 必须拒绝。
     *
     * 原实现要求两侧 UA 都非空才比对，而签发侧的 UA 由管理员自己的请求写入、
     * 攻击者控制不了；攻击者能控制的恰恰只有交换侧——截获 code 后发一个不带
     * User-Agent 的请求，就能整段跳过绑定校验。
     */
    public function test_login_as_exchange_rejects_empty_user_agent_when_issued_with_one(): void
    {
        $code = $this->issueLoginAsCode(self::ADMIN_USER_AGENT);

        $this->assertRejects(
            fn () => app(AuthService::class)->exchangeAdminLoginAsCode($code, '127.0.0.1', ''),
            '代登录环境校验失败',
            '交换侧不带 UA 时必须拒绝，不能视为「无从比对」而放行'
        );
    }

    /** UA 不一致必须拒绝（原实现已覆盖，一并钉住，避免以后只修一半）。 */
    public function test_login_as_exchange_rejects_mismatched_user_agent(): void
    {
        $code = $this->issueLoginAsCode(self::ADMIN_USER_AGENT);

        $this->assertRejects(
            fn () => app(AuthService::class)->exchangeAdminLoginAsCode($code, '127.0.0.1', 'curl/8.0'),
            '代登录环境校验失败'
        );
    }

    /** UA 一致时正常放行，且凭证单次消费。 */
    public function test_login_as_exchange_accepts_matching_user_agent_and_consumes_code(): void
    {
        $code = $this->issueLoginAsCode(self::ADMIN_USER_AGENT);

        $result = app(AuthService::class)->exchangeAdminLoginAsCode($code, '127.0.0.1', self::ADMIN_USER_AGENT);
        $this->assertNotSame('', trim((string) ($result['token'] ?? '')), 'UA 一致时应签发 token');

        $this->assertRejects(
            fn () => app(AuthService::class)->exchangeAdminLoginAsCode($code, '127.0.0.1', self::ADMIN_USER_AGENT),
            '代登录凭证已失效',
            '同一个 code 必须只能兑换一次'
        );
    }

    /**
     * 签发侧本身没带 UA 时维持放行。
     *
     * 该情形不受攻击者摆布（写入方是管理端自己的请求），此时无从绑定；
     * 凭证仍有 64 字符随机 + 单次消费 + 120s TTL 兜底。收紧成一律拒绝会直接
     * 打死脚本化调用管理端接口的正常场景。
     */
    public function test_login_as_exchange_allows_any_user_agent_when_issued_without_one(): void
    {
        $code = $this->issueLoginAsCode('');

        $result = app(AuthService::class)->exchangeAdminLoginAsCode($code, '127.0.0.1', 'curl/8.0');
        $this->assertNotSame('', trim((string) ($result['token'] ?? '')));
    }

    private const ADMIN_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TuraIDC-Admin';

    /** 用给定 UA 签发一个代登录凭证，返回 code。 */
    private function issueLoginAsCode(string $userAgent): string
    {
        config([
            'app.client_console_url' => 'https://console.example.test',
            'app.admin_url' => 'https://admin.example.test',
        ]);

        $issued = app(AuthService::class)->issueAdminLoginAsCode($this->makeClientUser(), [
            'ip_address' => '127.0.0.1',
            'user_agent' => $userAgent,
        ]);

        $code = (string) ($issued['login_code'] ?? '');
        $this->assertNotSame('', $code, '签发代登录凭证失败');

        return $code;
    }

    /** 断言闭包抛出携带指定文案的 BusinessException。 */
    private function assertRejects(callable $call, string $expectedMessage, string $because = ''): void
    {
        try {
            $call();
        } catch (BusinessException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage(), $because);

            return;
        }

        $this->fail($because !== '' ? $because : '预期抛出 BusinessException（'.$expectedMessage.'），实际未抛出');
    }

    private function makeClientUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::query()->create([
            'email' => 'session-revoke-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }
}
