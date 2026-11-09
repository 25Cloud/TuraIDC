<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceConsoleAssetService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

/**
 * 控制台能力接口里的「驱动级具名能力」标志位。
 *
 * 背景：快照 / ISO 面板的显隐由 `snapshot_supported` / `iso_supported` 决定，
 * 而这两个位与「自定义 HTML 模块」无关。魔方云这类直连驱动没有
 * `fetchCustomModulePage`（`fetchable=false`），如果把标志位算在模块解析成功分支里，
 * 两个面板会跟着自定义区域一起被藏掉——驱动明明有 `getSnapshots`/`getIsoImages`。
 */
class ServiceConsoleAreaCapabilityFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_flags_follow_driver_capabilities_even_when_custom_modules_are_absent(): void
    {
        $payload = $this->capabilitiesWith(new class
        {
            public function getSnapshots(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => []]];
            }

            public function getIsoImages(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => []]];
            }
        });

        $this->assertTrue($payload['snapshot_supported']);
        $this->assertTrue($payload['iso_supported']);
        // 驱动没有自定义模块页面，这一位必须仍然是 false
        $this->assertFalse($payload['fetchable']);
    }

    public function test_flags_are_false_when_the_driver_has_no_such_capability(): void
    {
        $payload = $this->capabilitiesWith(new class
        {
            public function login(Supplier $supplier): string
            {
                return 'jwt';
            }
        });

        $this->assertFalse($payload['snapshot_supported']);
        $this->assertFalse($payload['iso_supported']);
    }

    /**
     * 模块列表读取失败时，标志位仍按已探明的驱动能力回填。
     *
     * 这条是真正的回归点：上游没有 `supported_modules` 端点时，整段会落进 catch，
     * 若标志位只在成功分支赋值，快照/ISO 面板就永远不出现。
     */
    public function test_flags_survive_a_module_listing_failure(): void
    {
        $payload = $this->capabilitiesWith(
            new class
            {
                public function getSnapshots(Supplier $supplier, int $hostId, ?string $jwt = null): array
                {
                    return ['status' => 200, 'data' => ['list' => []]];
                }
            },
            throwOnModules: true
        );

        $this->assertFalse($payload['supported']);
        $this->assertTrue($payload['snapshot_supported']);
        $this->assertFalse($payload['iso_supported']);
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilitiesWith(object $runtime, bool $throwOnModules = false): array
    {
        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserService', 'canManageService', 'resolveUpstreamContext', 'fetchSupportedModules'])
            ->getMock();

        $service = (new ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        $service->id = 3458;
        $service->user_id = 2041;

        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->id = 2041;

        $supplier = new Supplier;
        $supplier->id = 11;

        $detailService->method('findUserService')->willReturn($service);
        $detailService->method('canManageService')->willReturn(true);
        $detailService->method('resolveUpstreamContext')->willReturn([$runtime, $supplier, 77, 'jwt']);

        if ($throwOnModules) {
            $detailService->method('fetchSupportedModules')->willThrowException(new \RuntimeException('上游没有 supported_modules'));
        } else {
            $detailService->method('fetchSupportedModules')->willReturn([]);
        }

        return (new ServiceConsoleAreaService($detailService, new ServiceConsoleAssetService))
            ->capabilitiesForUser($user, 3458);
    }
}
