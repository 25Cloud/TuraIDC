<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 电源状态读取失败的降级回归（纯逻辑单元测试：不启动 Laravel、不连数据库）。
 *
 * 电源状态是辅助信息，实例详情才是主数据：读电源失败只能降级成「仅同步实例详情」，
 * 不能中断整条状态同步；上游实例真的不存在/已删除（实例详情读不到）仍然必须判失败。
 *
 * 这里是通用链路（ServiceStatusSyncService）；ZJMF 插件链路的同一条边界见
 * tests/Unit/ZjmfPowerStatusRuntimeDegradationTest.php。
 */
class PowerStatusRuntimeDegradationTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        // 清掉上一个用例留下的门面实例缓存：否则 Log 会解析到已随应用一起销毁的旧 logger，
        // 它回头去读 config 时会在这个最小容器上炸掉，用例结果随执行顺序漂移。
        Facade::clearResolvedInstances();

        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('log', new class
        {
            /** @var array<int, array{0: string, 1: array<int, mixed>}> */
            public array $records = [];

            public function __call(string $method, array $arguments): void
            {
                $this->records[] = [$method, $arguments];
            }
        });
        $container->instance('config', new Repository([]));
        $container->instance('translator', new class
        {
            public function has(string $key): bool
            {
                return false;
            }

            public function get(string $key, array $replace = [], bool $fallback = true): string
            {
                return '';
            }
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_runtime_failure_degrades_to_host_detail_only(): void
    {
        $result = $this->callPrivate(
            $this->syncService(),
            'extractRuntimePayload',
            [
                $this->failedRuntimeResponse(),
                $this->service(),
                ['domainstatus' => 'active'],
                ProviderKey::ZJMF_FINANCE_API,
            ]
        );

        $this->assertSame([], $result['payload']);
        $this->assertSame('读取电源状态失败，请稍后重试', $result['error']);
    }

    public function test_runtime_unavailable_keeps_existing_silent_degradation(): void
    {
        $result = $this->callPrivate(
            $this->syncService(),
            'extractRuntimePayload',
            [
                [
                    'status_code' => 400,
                    'response' => ['status' => 400, 'msg' => '不能执行该操作'],
                ],
                $this->service(),
                ['domainstatus' => 'suspended'],
                ProviderKey::ZJMF_FINANCE_API,
            ]
        );

        // 上游明确表示电源状态不可用（实例暂停等）：不是故障，降级但不写同步失败原因。
        $this->assertSame([], $result['payload']);
        $this->assertNull($result['error']);
    }

    public function test_host_detail_failure_still_throws(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('读取主机详情失败');

        $this->callPrivate(
            $this->syncService(),
            'extractHostPayload',
            [
                ['status_code' => 200, 'response' => ['status' => 200, 'data' => ['host' => []]]],
                ProviderKey::ZJMF_FINANCE_API,
            ]
        );
    }

    /**
     * 上游电源状态接口返回非业务成功（对应生产日志里的「读取电源状态失败，请稍后重试」）。
     *
     * @return array<string, mixed>
     */
    private function failedRuntimeResponse(): array
    {
        return [
            'status_code' => 500,
            'response' => ['status' => 500, 'msg' => '上游电源接口异常'],
        ];
    }

    /**
     * Service 模型的构造函数会触发软删除作用域检查（要连库），这里绕开构造。
     */
    private function service(): Service
    {
        return (new ReflectionClass(Service::class))->newInstanceWithoutConstructor();
    }

    private function syncService(): ServiceStatusSyncService
    {
        return (new ReflectionClass(ServiceStatusSyncService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function callPrivate(object $target, string $method, array $arguments): mixed
    {
        $callable = (new ReflectionClass($target))->getMethod($method);
        $callable->setAccessible(true);

        return $callable->invokeArgs($target, $arguments);
    }
}
