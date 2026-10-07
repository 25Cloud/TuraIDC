<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Site\SeoRenderService;
use Tests\TestCase;

/**
 * 官网 SEO 渲染回归测试。
 *
 * 锁三件事：
 *  1. shell 里的默认 meta（robots/description/twitter 卡片）在动态渲染时被剥掉，
 *     只保留服务端注入的 head——裸壳 noindex 只对未被渲染的路径生效；
 *  2. 渲染页补齐社交卡片 meta（og:site_name / twitter 系列）；
 *  3. 未登记路径返回品牌 404 壳（noindex + HTTP 404 状态码）。
 */
class SeoRenderServiceTest extends TestCase
{
    private string $shellPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        // 临时 shell 模板放在系统临时目录，模拟前端构建产物里的默认 head
        $this->shellPath = (string) tempnam(sys_get_temp_dir(), 'seo-shell-');
        file_put_contents($this->shellPath, <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>默认标题</title>
<meta name="description" content="默认描述">
<meta name="robots" content="noindex">
<meta name="twitter:card" content="summary">
</head>
<body><div id="app"></div></body>
</html>
HTML);
        config(['idc.seo.frontend_shell_url' => 'file://'.$this->shellPath]);
    }

    protected function tearDown(): void
    {
        if ($this->shellPath !== '' && is_file($this->shellPath)) {
            @unlink($this->shellPath);
        }

        parent::tearDown();
    }

    public function test_rendered_page_strips_shell_defaults_and_injects_social_meta(): void
    {
        $service = $this->app->make(SeoRenderService::class);
        $rendered = $service->render('about');

        $this->assertSame(200, $rendered['status']);
        $html = $rendered['html'];

        // 动态渲染剥掉裸壳的 noindex 与默认 twitter 卡片，注入按页面配置的 head
        $this->assertStringNotContainsString('content="noindex"', $html);
        $this->assertSame(1, substr_count($html, 'twitter:card'));
        $this->assertSame(1, substr_count($html, 'og:site_name'));
        $this->assertStringContainsString('rel="canonical" href=', $html);
        $this->assertStringContainsString('/about', $html);
        // shell 的默认标题被动态标题替换
        $this->assertStringNotContainsString('默认标题', $html);
    }

    public function test_unknown_path_returns_noindex_brand_404_shell(): void
    {
        $service = $this->app->make(SeoRenderService::class);
        $rendered = $service->render('definitely-not-registered');

        $this->assertSame(404, $rendered['status']);
        $this->assertStringContainsString('noindex', $rendered['html']);
        $this->assertStringContainsString('页面不存在', $rendered['html']);
    }
}
