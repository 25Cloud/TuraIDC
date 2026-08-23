<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\User\UserService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 「改密即吊销 token」与「验密即签发 token」之间的序列化。
 *
 * 只吊销不加锁会留下竞态：一次并发登录可能已用旧密码通过校验，随后改密并吊销全部
 * token 的事务提交，而该登录才执行 createToken——吊销之后又长出一个有效 token，
 * 「处置盗号」这条通道恰好失效。改密侧的 UPDATE users 持有行锁，但拦不住无锁快照读，
 * 所以必须让登录侧也 lockForUpdate 并在持锁后重新校验密码。
 */
class PasswordChangeSessionRaceTest extends TestCase
{
    private const PROBE_CONNECTION = 'password_race_probe';

    /**
     * 登录必须真的在 users 行锁上排队。
     *
     * 这是本次修复的实质：光看代码结构无法区分「取了锁」与「看起来取了锁」，所以用另一
     * 条连接扣住该用户的行，再计时观察登录是否被挡住。修复前登录走无锁快照读，会立刻
     * 返回 200；修复后它必须等锁，并在 innodb_lock_wait_timeout 到点后失败。
     */
    public function test_client_login_waits_on_user_row_lock(): void
    {
        $user = $this->makeClientUser();

        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->table('users')->where('id', (int) $user->id)->lockForUpdate()->first();

        // 主连接不必真等 50 秒：1 秒足以区分「等锁」与「无锁直接放行」
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $startedAt = microtime(true);
        $blocked = false;

        try {
            $response = $this->postJson('/api/v2/client/login', [
                'account' => (string) $user->email,
                'password' => 'Temp@123456',
            ]);
            // 走到这里说明没被锁挡住——除非它返回的是锁等待导致的错误
            $blocked = $response->getStatusCode() >= 500;
        } catch (\Throwable) {
            $blocked = true;
        }

        $elapsed = microtime(true) - $startedAt;

        $probe->rollBack();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

        $this->assertTrue(
            $blocked,
            '他人持有 users 行锁时，登录不应还能顺利签发 token——那说明登录侧仍在走无锁读'
        );
        $this->assertGreaterThan(
            0.5,
            $elapsed,
            '登录应在行锁上真实等待（实测 '.round($elapsed, 3).'s），耗时过短说明根本没申请锁'
        );
    }

    /** 锁释放后登录恢复正常，不能把并发防护做成永久性阻塞。 */
    public function test_client_login_succeeds_after_lock_is_released(): void
    {
        $user = $this->makeClientUser();

        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->table('users')->where('id', (int) $user->id)->lockForUpdate()->first();
        $probe->rollBack();

        $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'Temp@123456',
        ])->assertOk()->assertJsonPath('data.user.id', (int) $user->id);
    }

    /**
     * 持锁后的二次校验必须以库里的当前密码为准。
     *
     * 管理员改密后，旧密码一律登录失败、新密码正常登录——确认加锁重验没把登录改坏，
     * 也确认改密本身生效。
     */
    public function test_password_change_invalidates_old_credentials_and_tokens(): void
    {
        $user = $this->makeClientUser();
        $user->createToken('client-token');
        $this->assertSame(1, $user->tokens()->count());

        app(UserService::class)->update($user, ['password' => 'NewSecret@123456']);

        $this->assertSame(0, $user->tokens()->count(), '改密必须吊销已签发 token');

        $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'Temp@123456',
        ])->assertStatus(422)->assertJsonPath('code', 40100);

        $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'NewSecret@123456',
        ])->assertOk();
    }

    /**
     * 忘记密码重置：包进事务后行为不变，仍然吊销全部 token。
     *
     * 原实现是裸的两条语句各自 autocommit（UPDATE 提交即释放行锁，delete 独立执行），
     * 窗口比 updateClientPassword 更宽。
     */
    public function test_reset_client_password_still_revokes_all_tokens(): void
    {
        $user = $this->makeClientUser();
        $user->createToken('client-token');
        $user->createToken('client-token');
        $this->assertSame(2, $user->tokens()->count());

        app(AuthService::class)->resetClientPassword($user, 'ResetSecret@123456');

        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'ResetSecret@123456',
        ])->assertOk();
    }

    /** 账号被禁用时，持锁后的复查仍要拦住登录。 */
    public function test_disabled_account_is_rejected_after_lock(): void
    {
        $user = $this->makeClientUser();
        $user->forceFill(['status' => 0])->save();

        $this->postJson('/api/v2/client/login', [
            'account' => (string) $user->email,
            'password' => 'Temp@123456',
        ])->assertStatus(403)->assertJsonPath('code', 40300);
    }

    private function probeConnection(): Connection
    {
        config([
            'database.connections.'.self::PROBE_CONNECTION => config('database.connections.'.config('database.default')),
        ]);

        return DB::connection(self::PROBE_CONNECTION);
    }

    private function makeClientUser(): User
    {
        return User::query()->create([
            'email' => 'pwd-race-'.bin2hex(random_bytes(6)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Pwd Race',
        ]);
    }

    protected function tearDown(): void
    {
        // 探针连接常驻会占着事务，务必清掉，否则后续用例会被自己的锁挡住
        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }
}
