<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 上游插件以「组合」方式持有 HostingPanelApiTransport 并转发调用，
 * 不是继承，拿不到 protected 权限。该传输层的公开方法一旦被收窄成
 * private/protected，插件侧会在跨类作用域抛 `Error: Call to private method`，
 * 被插件运行时兜底成「插件执行失败」——生产上表现为点「执行对接」必然失败，
 * 且本地无任何测试能拦住。
 *
 * 这里用反射把插件包装层实际转发的方法清单钉住，作为传输层的对外契约。
 */
class HostingPanelApiTransportContractTest extends TestCase
{
    /**
     * zjmf_finance 插件包装层转发到 HostingPanelApiTransport 的方法。
     * 取自 ZjmfFinanceTransport / ZjmfCatalogService / ZjmfFinanceAdapter /
     * ZjmfConsoleService / ZjmfAuthManager 中对 $this->transport->xxx() 的调用。
     */
    private const FORWARDED_METHODS = [
        'delete',
        'get',
        'getBalance',
        'getHostDetail',
        'getHostUpgradeConfigOptions',
        'httpClientOptions',
        'login',
        'loginResponse',
        'parallelGet',
        'post',
        'put',
        'request',
        'requestText',
        'requestWithMeta',
        'refreshJwt',
        'resolveSupplierRootUrl',
    ];

    #[Test]
    public function methods_forwarded_by_upstream_plugins_stay_public(): void
    {
        $reflection = new ReflectionClass(HostingPanelApiTransport::class);

        foreach (self::FORWARDED_METHODS as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "HostingPanelApiTransport::{$method}() 不存在，但上游插件包装层在转发它"
            );
            $this->assertTrue(
                $reflection->getMethod($method)->isPublic(),
                "HostingPanelApiTransport::{$method}() 必须是 public：上游插件以组合方式持有本类实例并转发调用，收窄可见性会让插件在跨类作用域直接抛 Error"
            );
        }
    }

    #[Test]
    public function it_strips_api_url_path_prefix_when_resolving_supplier_root_url(): void
    {
        $transport = new HostingPanelApiTransport;

        // forceFill：api_url 不在模型 fillable 内，普通批量赋值不会写入属性
        $withPath = new Supplier;
        $withPath->forceFill(['api_url' => 'https://example.com/sub/path']);
        $this->assertSame('https://example.com', $transport->resolveSupplierRootUrl($withPath));

        $withPort = new Supplier;
        $withPort->forceFill(['api_url' => 'https://example.com:8443/sub']);
        $this->assertSame('https://example.com:8443', $transport->resolveSupplierRootUrl($withPort));

        $bare = new Supplier;
        $bare->forceFill(['api_url' => 'https://example.com/']);
        $this->assertSame('https://example.com', $transport->resolveSupplierRootUrl($bare));
    }
}
