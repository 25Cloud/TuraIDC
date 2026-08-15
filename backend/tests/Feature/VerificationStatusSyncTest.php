<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\VerificationService;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 实名状态查询与落库语义：网络错误不得误判为认证失败；轮询状态无变化时跳过写库。
 */
class VerificationStatusSyncTest extends TestCase
{
    public function test_query_status_network_error_fallback_returns_network_error_status(): void
    {
        $service = $this->makeService();

        $result = $service->queryStatus('cert-network');

        // 驱动持续网络错误时，兜底必须返回网络错误状态(3)，而不是业务失败(2)。
        $this->assertSame(3, $result['status']);
        $this->assertStringContainsString('网络请求失败', $result['msg']);
    }

    public function test_network_error_does_not_mark_user_as_failed(): void
    {
        $user = $this->createUser('verification-network');
        $user->forceFill(['verification_status' => 4, 'verification_message' => '等待认证'])->save();
        $service = $this->makeService();

        $service->syncUserStatus(
            $user,
            ['status' => 3, 'msg' => '网络请求失败，请刷新页面重试'],
            'cert-network'
        );

        $fresh = $user->fresh();
        // 网络错误应保留待认证状态，不得落库为“认证失败”(3)。
        $this->assertSame(4, (int) $fresh->verification_status);
        $this->assertNotSame(3, (int) $fresh->verification_status);
    }

    public function test_repeated_poll_with_same_status_skips_db_write(): void
    {
        $user = $this->createUser('verification-poll');
        $service = $this->makeService();

        // 第一次同步：从未认证(0)写入待认证(4)。
        $service->syncUserStatus($user, ['status' => 4, 'msg' => '认证处理中'], 'cert-poll');

        $updateCount = 0;
        DB::listen(function ($query) use (&$updateCount): void {
            $sql = strtolower((string) $query->sql);
            if (str_starts_with($sql, 'update') && str_contains($sql, '`users`')) {
                $updateCount++;
            }
        });

        // 第二次同步：状态、消息、会话均未变化 → 应跳过写库。
        $service->syncUserStatus(
            $user->fresh(),
            ['status' => 4, 'msg' => '认证处理中'],
            'cert-poll'
        );

        $this->assertSame(0, $updateCount, '状态未变化时不应再次写入 users 表');
    }

    public function test_pending_status_change_still_persists(): void
    {
        $user = $this->createUser('verification-poll-change');
        $service = $this->makeService();

        // 第一次：待认证。
        $service->syncUserStatus($user, ['status' => 4, 'msg' => '认证处理中'], 'cert-change');

        // 消息变化（如审核通过）仍应写库，跳过逻辑不能吞掉真实状态迁移。
        // 注意：status=1 为驱动层“成功”内部码，syncUserStatus 会落库为业务状态 2。
        $service->syncUserStatus(
            $user->fresh(),
            ['status' => 1, 'msg' => '审核通过'],
            'cert-change'
        );

        $fresh = $user->fresh();
        $this->assertSame(2, (int) $fresh->verification_status);
        $this->assertSame(1, (int) $fresh->is_verified);
        $this->assertNotNull($fresh->verified_at);
    }

    private function makeService(): VerificationService
    {
        $resolver = new class extends IntegrationDriverBindingResolver
        {
            public function verificationDriverCandidates(): array
            {
                return ['verification-status-test'];
            }

            public function verificationContext(?string $driverKey = null): array
            {
                return ['plugin_id' => null, 'driver_key' => $driverKey ?: 'verification-status-test'];
            }
        };

        $driver = new class implements VerificationDriver
        {
            public function key(): string
            {
                return 'verification-status-test';
            }

            public function label(): string
            {
                return '实名状态测试驱动';
            }

            public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
            {
                return new VerificationInitializeResult(200, '初始化成功', 'status-test-certify-id');
            }

            public function generateScanUrl(string $certifyId): VerificationScanUrlResult
            {
                return new VerificationScanUrlResult(200, '获取成功', 'https://example.test/verification');
            }

            public function queryStatus(string $certifyId): VerificationStatusResult
            {
                return new VerificationStatusResult(3, '网络错误');
            }
        };

        return new VerificationService(
            new VerificationDriverManager([$driver], $resolver),
            null,
            $resolver,
        );
    }

    private function createUser(string $prefix): User
    {
        return User::query()->create([
            'email' => $prefix.'-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 0,
            'verification_status' => 0,
        ]);
    }
}
