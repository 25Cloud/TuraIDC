<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\Automation\Heartbeat\ScheduledTaskValidator;
use App\Services\System\SettingService;
use Tests\TestCase;
use Throwable;

/**
 * 核心定时任务运行契约回归。
 *
 * 心跳槽位固定 15 分钟粒度（TickSlot::SECONDS=900），cron 的分钟字段必须是
 * 00/15/30/45，否则注册期会被 ScheduledTaskValidator 抛异常，再由
 * HeartbeatTaskRegistry 静默跳过：任务既不执行，管理端也无法手动重跑。
 * 这类错误在代码里毫无征兆（服务照常启动、日志一片安静），只有契约测试能拦住。
 */
class CoreScheduledTaskContractTest extends TestCase
{
    public function test_every_core_task_satisfies_runtime_contract(): void
    {
        $validator = new ScheduledTaskValidator;
        $provider = new CoreScheduledTaskProvider($this->stubSettingService());

        $checked = [];

        foreach ($provider->tasks() as $task) {
            try {
                $validator->validate($task, CoreScheduledTaskProvider::class);
            } catch (Throwable $exception) {
                $this->fail("核心任务 {$task->key()} 不符合运行契约，注册时会被静默跳过：".$exception->getMessage());
            }

            $checked[] = $task->key();
        }

        $this->assertNotEmpty($checked, '核心任务清单为空，契约校验无意义。');
    }

    private function stubSettingService(): SettingService
    {
        $service = $this->createMock(SettingService::class);
        $service->method('getAutomationConfig')->willReturn([]);

        return $service;
    }
}
