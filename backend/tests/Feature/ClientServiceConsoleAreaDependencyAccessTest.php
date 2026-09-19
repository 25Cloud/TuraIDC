<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceConsoleAssetService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 自定义控制台区域子服务的依赖访问边界回归。
 *
 * 背景：ServiceConsoleAreaService 曾直接读取 ServiceDetailService 的私有属性
 * $transformService 判断「是否接入可控上游」，导致
 * GET /api/v2/client/services/{id}/console/capabilities 必然抛
 * "Cannot access private property" 并返回 500（前端提示网络异常）。
 *
 * 这里锁定两点：
 *   1) 判定必须走 ServiceDetailService 的公开代理方法 canManageService()；
 *   2) 不可控上游时各公开入口按约定降级，不抛未捕获错误。
 *
 * 继承裸 PHPUnit TestCase：本用例不启动 Laravel、不连数据库，只校验调用契约与降级行为，
 * 因此在任何环境（含生产机、无测试库）都可安全执行。
 */
class ClientServiceConsoleAreaDependencyAccessTest extends TestCase
{
    #[Test]
    public function area_service_only_reaches_detail_service_through_public_api(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ServiceConsoleAreaService::class))->getFileName()
        );

        // 只允许 $this->transformService（自身依赖），禁止 detailService->transformService 这类越权读取
        $crossObjectAccess = preg_match_all('/(?<!\$this)->transformService/', $source);

        $this->assertSame(
            0,
            $crossObjectAccess,
            'ServiceConsoleAreaService 不得读取其他对象的私有属性 $transformService，应改用 ServiceDetailService::canManageService()'
        );
    }

    #[Test]
    public function detail_service_keeps_transform_service_private_behind_public_proxy(): void
    {
        $this->assertTrue(
            (new ReflectionProperty(ServiceDetailService::class, 'transformService'))->isPrivate(),
            'ServiceDetailService::$transformService 应保持私有，外部只能走公开方法'
        );
        $this->assertTrue(
            (new ReflectionMethod(ServiceDetailService::class, 'canManageService'))->isPublic(),
            '子服务依赖的公开代理 canManageService() 不能被收窄可见性'
        );

        // 外部直接读取私有属性必须失败——这与生产上 500 的报错形态一致
        $detailService = (new ReflectionClass(ServiceDetailService::class))->newInstanceWithoutConstructor();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access private property');

        $detailService->transformService;
    }

    #[Test]
    public function capabilities_degrade_without_managed_upstream_instead_of_erroring(): void
    {
        $areaService = $this->makeAreaService(canManage: false);

        $payload = $areaService->capabilitiesForUser($this->makeUser(), 3458);

        $this->assertSame([
            'supported' => false,
            'error' => '',
            'areas' => [],
            'nat_supported' => false,
            'monitor_supported' => false,
            'fetchable' => false,
        ], $payload);
    }

    #[Test]
    public function ticket_issuing_rejects_service_without_managed_upstream(): void
    {
        $areaService = $this->makeAreaService(canManage: false);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前服务暂不支持自定义功能面板');

        $areaService->areaTicketForUser($this->makeUser(), 3458);
    }

    #[Test]
    public function passthrough_helpers_return_null_without_managed_upstream(): void
    {
        $areaService = $this->makeAreaService(canManage: false);
        $service = $this->makeService();

        $this->assertNull($areaService->passthroughModulePayload($service));
        $this->assertNull($areaService->proxyModulePage($service, 'nat_acl'));
        $this->assertNull($areaService->proxyModuleAction($service, ['action' => 'delete']));
    }

    /**
     * 只把 ServiceDetailService 换成桩：本用例验证的正是「子服务如何调用 detailService」，
     * 因此不能让被测对象 ServiceConsoleAreaService 一起变成 mock。
     */
    private function makeAreaService(bool $canManage): ServiceConsoleAreaService
    {
        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserService', 'canManageService'])
            ->getMock();
        $detailService->method('findUserService')->willReturn($this->makeService());
        $detailService->method('canManageService')->willReturn($canManage);

        return new ServiceConsoleAreaService($detailService, new ServiceConsoleAssetService());
    }

    /**
     * 不执行 Eloquent 构造：避免在没有应用上下文时触发模型 boot（Schema 探测），
     * 本用例只需要 id / user_id 参与缓存键与归属判断。
     */
    private function makeService(): Service
    {
        $service = (new ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        $service->id = 3458;
        $service->user_id = 2041;

        return $service;
    }

    private function makeUser(): User
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->id = 2041;

        return $user;
    }
}
