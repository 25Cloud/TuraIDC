<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SeoRenderService;
use Illuminate\Http\Response;

/**
 * 官网 SEO 渲染出口。
 * Nginx 将官网公开路径（/、/products、落地页、公告/帮助等）转发到
 * /seo/www/{path}，由 Laravel 读数据库动态渲染完整 HTML 供搜索引擎采集。
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly SeoRenderService $seoRenderService,
    ) {}

    public function render(string $path = ''): Response
    {
        $html = $this->seoRenderService->render($path);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function robots(): Response
    {
        return response($this->seoRenderService->robots(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        return response($this->seoRenderService->sitemap(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
