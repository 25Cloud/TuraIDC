<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\ProductCatalog\ProductDescriptionService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 商品描述上游同步的回归。
 *
 * 三条口径要锁死：
 *
 *   1. 上游描述归一只有一份实现：导入路径、remark 兜底与「从上游拉」共用同一条管线，
 *      同一段上游 HTML 在三条路径下必须得到同一结果，否则同一份内容会在不同入口显示不同；
 *   2. 定时任务必须挂在心跳任务体系里（而不是 routes/console.php 里另加 Schedule::command），
 *      否则 heartbeat 侧的台账与重跑能力会绕过这条任务；
 *   3. 管理端接口面要齐全：单商品、批量、分组三档的同步与开关，以及全局节拍的读写。
 */
class ProductDescriptionUpstreamSyncTest extends TestCase
{
    public function test_it_normalizes_upstream_description_html(): void
    {
        $service = new ProductDescriptionService;

        // 上游返回的是转义过的卡片 HTML
        $this->assertSame(
            '<ul><li>CPU: 8核</li></ul>',
            $service->normalizeUpstreamHtml('&lt;li&gt;CPU: 8核&lt;/li&gt;'),
        );

        // 已经是完整列表时不重复包容器
        $this->assertSame(
            '<ul><li>CPU: 8核</li></ul>',
            $service->normalizeUpstreamHtml('<ul><li>CPU: 8核</li></ul>'),
        );

        // 危险标签不留
        $this->assertStringNotContainsString(
            'script',
            $service->normalizeUpstreamHtml('<script>alert(1)</script><p>正文</p>'),
        );

        $this->assertSame('', $service->normalizeUpstreamHtml(null));
        $this->assertSame('', $service->normalizeUpstreamHtml('   '));
    }

    public function test_it_uses_the_same_pipeline_for_the_remark_fallback(): void
    {
        $service = new ProductDescriptionService;

        $upstream = '&lt;li&gt;CPU: 8核&lt;/li&gt;';

        $resolved = $service->resolve(new Product([
            'description' => '',
            'remark' => $upstream,
        ]));

        // remark 兜底与上游同步必须产出同一段 HTML
        $this->assertSame(
            $service->normalizeUpstreamHtml($upstream),
            $resolved['html'],
        );
        $this->assertSame(ProductDescriptionService::SOURCE_REMARK, $resolved['source']);
    }

    public function test_it_registers_the_description_sync_task_in_the_heartbeat_provider(): void
    {
        $tasks = app(CoreScheduledTaskProvider::class)->tasks();

        $keys = array_map(
            static fn (object $task): string => (string) $task->key(),
            $tasks,
        );

        $this->assertContains('product-description-sync', $keys);

        $task = collect($tasks)->first(
            static fn (object $item): bool => (string) $item->key() === 'product-description-sync',
        );

        $this->assertNotNull($task);
        $this->assertSame('商品描述同步', $task->title());
        $this->assertNotEmpty($task->triggers());
    }

    public function test_it_exposes_the_description_sync_admin_routes(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        foreach ([
            'api/v2/admin/products/description/sync-batch',
            'api/v2/admin/products/description/auto-sync',
            'api/v2/admin/products/description/sync-settings',
            'api/v2/admin/products/{product}/description/sync',
            'api/v2/admin/product-groups/{group}/description/sync',
            'api/v2/admin/product-groups/{group}/description/auto-sync',
        ] as $expected) {
            $this->assertContains($expected, $uris, '缺少路由：'.$expected);
        }
    }
}
