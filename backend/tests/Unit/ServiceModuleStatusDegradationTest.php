<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClientServiceConsole\ServiceDetailService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 电源状态读取失败的降级回归。
 *
 * 管理端 / 控制台的「同步信息」把「读电源状态」和「刷新实例详情」串在同一个动作里。
 * 实例关机、暂停或上游接口临时不可用时电源状态必然读不到，但到期时间、商品、IP
 * 仍然必须同步——所以读电源失败只能降级成 available=false，不能抛 42200 打断同步。
 *
 * 这两个方法只做纯数组归一化，不依赖容器与数据库，因此直接绕过构造函数实例化，
 * 让回归在无测试库的环境也能跑。
 */
class ServiceModuleStatusDegradationTest extends TestCase
{
    private function service(): ServiceDetailService
    {
        return (new ReflectionClass(ServiceDetailService::class))->newInstanceWithoutConstructor();
    }

    public function test_host_module_status_degrades_to_unavailable_instead_of_throwing(): void
    {
        $status = $this->service()->buildUnavailableModuleStatus('host', '电源状态失败，请稍后重试');

        $this->assertSame('host', $status['type']);
        $this->assertSame('电源状态', $status['type_label']);
        $this->assertSame('unavailable', $status['status']);
        $this->assertFalse($status['available']);
        $this->assertSame('电源状态失败，请稍后重试', $status['error']);
        $this->assertSame('电源状态失败，请稍后重试', $status['description']);
        $this->assertTrue($status['is_finished']);
        $this->assertFalse($status['is_failed']);
        $this->assertSame(['degraded' => true], $status['raw']);
    }

    public function test_unavailable_module_status_falls_back_to_generic_reason(): void
    {
        $status = $this->service()->buildUnavailableModuleStatus('host', '   ');

        $this->assertFalse($status['available']);
        $this->assertSame('上游暂未返回状态信息', $status['error']);
    }

    public function test_normal_module_status_is_marked_available(): void
    {
        $status = $this->service()->normalizeModuleStatus(['status' => 'on', 'des' => '运行中'], 'host');

        $this->assertTrue($status['available']);
        $this->assertSame('on', $status['status']);
        $this->assertSame('运行中', $status['description']);
    }
}
