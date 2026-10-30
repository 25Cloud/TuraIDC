<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 实例详情远程抓取范围回归。
 *
 * 一次「同步信息」要拉上游：主机详情、电源状态、NAT 远程地址、自定义区域 HTML。
 * 生产实测这套组合要 26 秒，其中自定义区域 HTML（云服务器上是快照/备份/安全组页）
 * 独占 18 秒——它只用于拼面板入口，云服务器根本用不上；NAT 远程地址也只对 NAT 控制台有意义。
 * 不收窄抓取范围就会把客户端顶到超时之外，界面误报「同步失败」。
 *
 * 这两个判定只依赖实例与商品属性，不碰数据库，因此绕过构造函数实例化，
 * 让回归在无测试库的环境也能跑。
 */
class ServiceDetailRemoteFetchScopeTest extends TestCase
{
    public function test_compute_product_skips_area_fragments(): void
    {
        $service = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_COMPUTE);

        $this->assertFalse($this->invokeAreaFragments($service));
    }

    public function test_product_without_template_defaults_to_compute_and_skips_area_fragments(): void
    {
        $service = $this->serviceWithProduct(null);

        $this->assertFalse(
            $this->invokeAreaFragments($service),
            '未归类的商品按云服务器处理：商品模型会把模板归一成 compute'
        );
    }

    public function test_panel_product_keeps_area_fragments(): void
    {
        foreach ([Product::CONSOLE_TEMPLATE_CDN, Product::CONSOLE_TEMPLATE_VIRTUALHOST, Product::CONSOLE_TEMPLATE_PORT_MAPPING] as $template) {
            $service = $this->serviceWithProduct($template);

            $this->assertTrue(
                $this->invokeAreaFragments($service),
                '面板型商品必须继续抓取上游自定义区域：'.$template
            );
        }
    }

    public function test_nat_console_keeps_nat_fetch(): void
    {
        $service = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_COMPUTE);

        $this->assertTrue($this->invokeNatFetch($service, 'nat'));
    }

    public function test_default_console_skips_nat_fetch(): void
    {
        $service = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_COMPUTE);

        $this->assertFalse($this->invokeNatFetch($service, 'default'));
    }

    /**
     * 运营在后台把「控制台面板」选成端口映射，等于声明这台机器走 NAT 转发。
     *
     * 实例 3965（NAT 游戏云，product_type=game_cloud）的分组名里没有「云电脑」关键词，
     * 只看商品类型永远判不成 nat，NAT 远程地址就一直抓不到、详情页「远程地址」是 --。
     * 前端 isNatConsole() 按 console_template=port_mapping 判定，后端必须同口径。
     */
    public function test_port_mapping_template_keeps_nat_fetch(): void
    {
        $service = $this->serviceWithProduct(Product::CONSOLE_TEMPLATE_PORT_MAPPING);

        $this->assertTrue(
            $this->invokeNatFetch($service, 'default'),
            '端口映射控制台必须抓上游 NAT 远程地址'
        );
    }

    public function test_background_sync_dispatch_is_public(): void
    {
        $method = new ReflectionMethod(ServiceDetailService::class, 'dispatchBackgroundSync');

        $this->assertTrue($method->isPublic(), '后台同步派发入口必须是公开方法');
    }

    private function serviceWithProduct(?string $consoleTemplate): Service
    {
        $product = new Product;
        if ($consoleTemplate !== null) {
            $product->setAttribute('console_template', $consoleTemplate);
        }
        // 预置关系，loadMissing 才不会去查库
        $product->setRelation('productGroup', null);

        // 绕过构造函数：Service 的软删除作用域在启动时就要查库
        $service = (new ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        $service->setRelation('product', $product);

        return $service;
    }

    private function invokeAreaFragments(Service $service): bool
    {
        $detail = (new ReflectionClass(ServiceDetailService::class))->newInstanceWithoutConstructor();

        $call = new ReflectionMethod(ServiceDetailService::class, 'shouldFetchAreaFragments');

        return (bool) $call->invoke($detail, $service);
    }

    private function invokeNatFetch(Service $service, string $consoleMode): bool
    {
        $detail = (new ReflectionClass(ServiceDetailService::class))->newInstanceWithoutConstructor();

        $resolver = new ReflectionProperty(ServiceDetailService::class, 'resolverService');
        $resolver->setValue($detail, new StubConsoleModeResolver($consoleMode));

        $call = new ReflectionMethod(ServiceDetailService::class, 'shouldFetchNatRemote');

        return (bool) $call->invoke($detail, $service);
    }
}

/**
 * 只回固定控制台模式，避免回归用例去查商品分组（需要数据库）。
 */
final class StubConsoleModeResolver extends ServiceResolverService
{
    public function __construct(private readonly string $consoleMode) {}

    public function resolveConsoleMode(Service $service, array $provisionData = []): string
    {
        return $this->consoleMode;
    }
}
