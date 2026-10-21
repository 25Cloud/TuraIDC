<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\User\Concerns\HandlesAdminUserServices;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 管理端「切换上游绑定」改为本地写立即返回的回归。
 *
 * 生产实证：切换上游绑定调用 PUT /api/v2/admin/users/{user}/services/{service}/meta，
 * 旧实现结尾强制 getDetailForUser($user, $serviceId, true) 同步拉取上游，
 * 单次 fetchRemoteState 实测 26.2s，超过管理端 axios 的 10s 超时：
 * nginx 记 499、界面提示「失败」，但数据库其实已经写入
 * （service_upstream_bindings 已出现新的 upstream_service_id 行）。
 *
 * 本用例不连库：主机 ID 列表收敛是纯数组逻辑，直接实例化 trait 调用；
 * 「不再强制远端刷新」用源码守卫断言（仓库已有同类写法：ScheduleConsoleHookCoverageTest）。
 */
class AdminUserServiceMetaLocalWriteTest extends TestCase
{
    /**
     * 事务里的真实顺序：先写 upstream_host_id，再收敛开通时留下的主机 ID 列表。
     */
    public function test_switching_upstream_host_replaces_stale_upstream_host_ids(): void
    {
        $subject = $this->subject();
        $method = new ReflectionMethod($subject, 'syncUpstreamHostIdsAfterSwitch');

        $provisionData = $method->invoke($subject, [
            'source_type' => 'upstream',
            'upstream_host_id' => 10308,
            'upstream_host_ids' => [7435],
        ], 10308, true);

        $this->assertSame(10308, $provisionData['upstream_host_id']);
        $this->assertSame([10308], $provisionData['upstream_host_ids'], '切换上游主机后不能继续保留旧主机 ID');
        $this->assertSame('upstream', $provisionData['source_type']);
    }

    public function test_keeping_the_same_upstream_host_leaves_the_host_id_list_untouched(): void
    {
        $subject = $this->subject();
        $method = new ReflectionMethod($subject, 'syncUpstreamHostIdsAfterSwitch');

        $provisionData = $method->invoke($subject, [
            'source_type' => 'upstream',
            'upstream_host_id' => 7435,
            'upstream_host_ids' => [7435],
        ], 7435, false);

        $this->assertSame([7435], $provisionData['upstream_host_ids']);
    }

    public function test_meta_update_returns_local_snapshot_without_forcing_remote_refresh(): void
    {
        $code = $this->updateServiceMetaCode();

        $this->assertStringContainsString(
            'getDetailForUser($user, $serviceId, false)',
            $code,
            '更新实例业务信息必须读本地快照返回，不能在请求内强制拉取上游'
        );
        $this->assertStringNotContainsString(
            'getDetailForUser($user, $serviceId, true)',
            $code,
            '强制远端刷新会让请求耗时 26s+，超过前端 10s 超时并导致界面误报失败'
        );
    }

    public function test_meta_update_dispatches_background_sync_after_upstream_binding_changed(): void
    {
        $code = $this->updateServiceMetaCode();
        $guard = 'if ($supplierChanged || $supplierProductChanged || $upstreamHostChanged) {';
        $dispatch = 'app(ServiceDetailService::class)->dispatchBackgroundSync($service);';

        $this->assertStringContainsString($dispatch, $code, '上游绑定变化后必须派发一次后台同步');
        $this->assertGreaterThan(
            strrpos($code, $guard),
            strpos($code, $dispatch),
            '后台同步必须由「上游绑定变化」分支守卫，未变化时不额外打扰上游'
        );
    }

    /**
     * 直接使用 trait，避免拉起 UserService 的容器依赖（构造函数需要一整套服务）。
     */
    private function subject(): object
    {
        return new class
        {
            use HandlesAdminUserServices;
        };
    }

    /**
     * 取 updateServiceMeta 的方法体，并去掉整行注释：
     * 注释里会引用旧实现（getDetailForUser(..., true)）作为背景说明，不能当成代码判定。
     */
    private function updateServiceMetaCode(): string
    {
        $method = new ReflectionMethod(HandlesAdminUserServices::class, 'updateServiceMeta');
        $file = $method->getFileName();
        $this->assertIsString($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $method->getStartLine() + 1;
        $body = array_slice($lines, $start, $length);
        $code = array_filter($body, fn (string $line): bool => ! str_starts_with(trim($line), '//'));

        return implode("\n", $code);
    }
}
