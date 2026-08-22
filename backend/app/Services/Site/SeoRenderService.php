<?php

declare(strict_types=1);

namespace App\Services\Site;

use App\Models\ContentArticle;
use App\Services\Content\ContentArticleService;
use App\Support\ContentPublishedCacheVersion;
use App\Support\Seo\SeoLandingPages;
use App\Support\SiteConfigPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 官网 SEO 动态渲染服务。
 *
 * 解决 SPA 无 SEO 问题：以前端构建产物 index.html 为模板，动态注入
 * head meta（站点名 / Logo 均来自数据库配置）、JSON-LD 结构化数据与
 * 页面正文快照，爬虫与无 JS 用户可直接读取完整内容；浏览器加载 JS 后
 * Vue 挂载接管交互（正文位于 #app 内，挂载时自动被替换，无重复内容）。
 */
class SeoRenderService
{
    private const SHELL_CACHE_KEY = 'seo:www:shell';
    private const PAGE_CACHE_PREFIX = 'seo:www:page:';

    public function __construct(
        private readonly SiteHomeService $siteHomeService,
        private readonly ContentArticleService $contentArticleService,
        private readonly SiteProductReadService $siteProductReadService,
    ) {}

    /**
     * 渲染指定公开路径的完整 HTML 文档。
     */
    public function render(string $path): string
    {
        $page = $this->resolvePage($path);

        if ($page === null) {
            abort(404);
        }

        $cacheKey = self::PAGE_CACHE_PREFIX.$path.':v'.ContentPublishedCacheVersion::current();

        return Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('idc.seo.cache_ttl', 300)),
            function () use ($page): string {
                $shell = $this->fetchShellTemplate();
                $head = $this->renderHead($page);
                $body = $this->renderBody($page);

                return $this->assembleDocument($shell, $head, $body);
            }
        );
    }

    /**
     * 生成 robots.txt 内容。
     */
    public function robots(): string
    {
        $siteUrl = $this->siteUrl();

        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api',
            'Disallow: /seo',
            'Disallow: /console',
            'Disallow: /admin',
            'Disallow: /client',
            '',
            "Sitemap: {$siteUrl}/sitemap.xml",
            '',
        ]);
    }

    /**
     * 生成 sitemap.xml 内容。
     */
    public function sitemap(): string
    {
        $contentVersion = ContentPublishedCacheVersion::current();

        $xml = Cache::remember(
            'seo:sitemap:v'.$contentVersion,
            now()->addSeconds((int) config('idc.seo.cache_ttl', 300)),
            fn (): string => $this->buildSitemapXml()
        );

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".$xml;
    }

    /**
     * 解析公开路径，返回页面描述数组（meta + 数据），未知路径返回 null。
     *
     * @return array<string, mixed>|null
     */
    private function resolvePage(string $path): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $first = $segments[0] ?? '';

        return match ($first) {
            '' => $this->homePage(),
            'products' => count($segments) > 2 ? null : $this->productsPage($segments),
            'about' => $this->staticPage('about', '关于我们', '了解图拉云的 IDC 服务能力、节点覆盖和平台优势。'),
            'terms' => $this->staticPage('terms', '服务条款', '查看图拉云服务条款。'),
            'privacy' => $this->staticPage('privacy', '隐私政策', '查看图拉云隐私政策。'),
            'notices' => $this->articleListPage('notices', '官方公告', '查看图拉云平台公告和服务通知。', ContentArticle::TYPE_NOTICE, $segments),
            'help' => $this->articleListPage('help', '帮助中心', '查看图拉云产品购买、账单支付和服务管理帮助。', ContentArticle::TYPE_HELP, $segments),
            default => $this->landingPage($first),
        };
    }

    private function homePage(): array
    {
        $config = SiteConfigPayload::payload();
        $siteName = $this->siteName($config);
        $overview = $this->siteHomeService->overview();
        $typeNames = collect($overview['root_groups'] ?? [])
            ->pluck('product_type_label')
            ->filter()
            ->unique()
            ->take(3)
            ->implode('、');

        $description = $typeNames !== ''
            ? $siteName.'提供'.$typeNames.'等云服务与 IDC 解决方案，覆盖香港、美国与国内多地节点。'
            : $siteName.'提供云服务器、独立服务器、云电脑与 IDC 服务，覆盖香港、美国与国内多地节点。';

        return [
            'type' => 'home',
            'title' => '',
            'description' => $description,
            'keywords' => '云服务器,独立服务器,高防服务器,云电脑,IDC 服务,'.$siteName,
            'canonical' => '/',
            'robots' => '',
            'data' => $overview,
        ];
    }

    private function productsPage(array $segments): array
    {
        $siteName = $this->siteName();
        $productId = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : 0;

        if ($productId > 0) {
            $product = $this->siteProductReadService->productDetail($productId);

            if ($product === null) {
                return [
                    'type' => 'products',
                    'title' => '产品与服务',
                    'description' => '浏览'.$siteName.'云服务器、独立服务器、云电脑与 IDC 产品方案。',
                    'keywords' => '产品,云服务器,独立服务器,'.$siteName,
                    'canonical' => '/products',
                    'robots' => 'noindex,nofollow',
                    'data' => null,
                ];
            }

            $productName = (string) ($product['combined_display_name'] ?? $product['name'] ?? '');
            $groupName = (string) ($product['group']['full_name'] ?? $product['group']['name'] ?? '');
            $spec = (string) ($product['instance_spec_text'] ?? '');
            $price = (string) ($product['primary_price'] ?? '');
            $cycle = (string) ($product['primary_cycle'] ?? '');
            $description = trim("{$groupName} {$productName}".($spec !== '' ? "，{$spec}" : '').'。'
                .($price !== '' ? "{$siteName}提供在线购买、部署与工单支持。" : $siteName.'提供在线购买、部署与工单支持。'));

            return [
                'type' => 'product_detail',
                'title' => $productName !== '' ? $productName : '产品详情',
                'description' => $description,
                'keywords' => "{$productName},云服务器,独立服务器,{$siteName}",
                'canonical' => "/products/{$productId}",
                'robots' => '',
                'data' => [
                    'id' => $productId,
                    'name' => $productName,
                    'spec' => $spec,
                    'price' => $price,
                    'cycle' => $cycle,
                    'group_name' => $groupName,
                    'group_slogan' => (string) ($product['group']['slogan'] ?? ''),
                ],
            ];
        }

        return [
            'type' => 'products',
            'title' => '产品与服务',
            'description' => '浏览'.$siteName.'云服务器、独立服务器、云电脑与 IDC 产品方案。',
            'keywords' => '产品,云服务器,独立服务器,高防服务器,云电脑,'.$siteName,
            'canonical' => '/products',
            'robots' => '',
            'data' => [
                'types' => $this->siteProductReadService->productTypes()['list'] ?? [],
                'groups' => $this->siteProductReadService->productGroups()['list'] ?? [],
            ],
        ];
    }

    private function landingPage(string $slug): ?array
    {
        $page = SeoLandingPages::findByPath('/'.$slug);

        if ($page === null) {
            return null;
        }

        $groups = $this->siteProductReadService->productGroups()['list'] ?? [];

        return [
            'type' => 'landing',
            'title' => $page['title'],
            'description' => $page['description'],
            'keywords' => $page['keywords'],
            'canonical' => $page['path'],
            'robots' => '',
            'data' => [
                'landing' => $page,
                'groups' => $groups,
            ],
        ];
    }

    private function staticPage(string $type, string $title, string $description): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'keywords' => $type === 'about' ? '关于我们,IDC 服务,'.$this->siteName() : $title.','.$this->siteName(),
            'canonical' => '/'.$type,
            'robots' => '',
            'data' => null,
        ];
    }

    private function articleListPage(string $type, string $title, string $description, string $articleType, array $segments): array
    {
        $articleId = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : 0;

        if ($articleId > 0) {
            return $this->articleDetailPage($type, $articleType, $articleId);
        }

        $articles = $this->contentArticleService->publishedList($articleType, [], 50)->items();

        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'keywords' => $title.','.$this->siteName(),
            'canonical' => '/'.$type,
            'robots' => '',
            'data' => [
                'articles' => $this->transformArticles($articles),
            ],
        ];
    }

    private function articleDetailPage(string $type, string $articleType, int $articleId): array
    {
        try {
            $article = $this->contentArticleService->publishedDetail($articleType, $articleId);
        } catch (\Throwable) {
            abort(404);
        }

        $articleTitle = (string) $article->title;
        $summary = trim((string) $article->summary);
        $excerpt = $summary !== '' ? $summary : Str::limit(
            preg_replace('/\s+/u', ' ', strip_tags(Str::markdown((string) $article->content))),
            120,
            '...'
        );

        return [
            'type' => $type === 'notice_detail' ? 'notice_detail' : 'help_detail',
            'title' => $articleTitle,
            'description' => $excerpt,
            'keywords' => $articleTitle.','.$this->siteName(),
            'canonical' => '/'.$type.'/'.$articleId,
            'robots' => '',
            'data' => [
                'id' => (int) $article->id,
                'title' => $articleTitle,
                'summary' => $summary,
                'content_html' => $this->sanitizeHtml(Str::markdown((string) $article->content)),
                'publish_at' => optional($article->publish_at)?->toDateString(),
                'category' => (string) ($article->contentCategory?->name ?: $article->category_name ?: ''),
            ],
        ];
    }

    /**
     * 抓取前端构建产物 index.html 作为页面模板，带缓存。
     */
    private function fetchShellTemplate(): string
    {
        return Cache::remember(
            self::SHELL_CACHE_KEY,
            now()->addSeconds((int) config('idc.seo.shell_cache_ttl', 600)),
            function (): string {
                $url = (string) config('idc.seo.frontend_shell_url', 'http://frontends:8081/index.html');
                $html = '';

                if (str_starts_with($url, 'file://')) {
                    // 源码部署：直接读同机前端构建产物
                    $html = @file_get_contents(substr($url, 7));
                } else {
                    $response = Http::timeout(5)->get($url);
                    $html = $response->ok() ? $response->body() : '';
                }

                if (! is_string($html) || trim($html) === '') {
                    report(new \RuntimeException("SeoRenderService: 无法读取前端 shell 模板: {$url}"));

                    return $this->fallbackShell();
                }

                return $html;
            }
        );
    }

    /**
     * shell 读取失败时的最小回退模板（仅保证可响应，资源引用需随前端配置）。
     */
    private function fallbackShell(): string
    {
        $siteName = $this->siteName();

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$siteName}</title>
</head>
<body>
<div id="app"></div>
</body>
</html>
HTML;
    }

    /**
     * 组装最终文档：替换 shell 的 head 与 #app 内容。
     */
    private function assembleDocument(string $shell, string $headBlock, string $bodyBlock): string
    {
        // 移除 shell 中原有的 title / 动态 meta / canonical，避免与注入的 head 冲突
        $shell = preg_replace('/<title>.*?<\/title>/is', '', $shell);
        $shell = preg_replace('/\s*<meta\s+name="(?:description|keywords|author|robots)"[^>]*\/?>\s*/i', '', $shell);
        $shell = preg_replace('/\s*<meta\s+property="og:[^"]*"[^>]*\/?>\s*/i', '', $shell);
        $shell = preg_replace('/\s*<link\s+rel="canonical"[^>]*\/?>\s*/i', '', $shell);

        // 在 </head> 前注入动态 head 块（使用回调避免 head 内容中的 $ 被替换解析）
        $shell = preg_replace_callback(
            '/<\/head>/i',
            static fn (array $matches): string => $headBlock."\n</head>",
            $shell,
            1
        );

        // 在 <div id="app"> 内填充正文快照；Vue 挂载时自动替换
        $shell = preg_replace_callback(
            '/(<div\s+id="app"[^>]*>)(\s*)(<\/div>)/i',
            static fn (array $matches): string => $matches[1].$bodyBlock.$matches[3],
            $shell,
            1
        );

        return $shell;
    }

    /**
     * 生成动态 head 块（title + meta + canonical + JSON-LD）。
     */
    private function renderHead(array $page): string
    {
        $config = SiteConfigPayload::payload();
        $siteName = $this->siteName($config);
        $siteUrl = $this->siteUrl();
        $canonical = $siteUrl.(($page['canonical'] ?? '/') === '/' ? '/' : rtrim('/'.ltrim((string) $page['canonical'], '/'), '/'));
        $logoUrl = $this->absoluteAssetUrl($siteUrl, (string) ($config['site_logo'] ?? ''));

        $pageTitle = (string) ($page['title'] ?? '');
        $fullTitle = $pageTitle !== ''
            ? (str_contains($pageTitle, $siteName) ? $pageTitle : $pageTitle.' - '.$siteName)
            : $siteName;

        $description = htmlspecialchars((string) ($page['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $keywords = htmlspecialchars((string) ($page['keywords'] ?? ''), ENT_QUOTES, 'UTF-8');
        $robots = (string) ($page['robots'] ?? '');
        $robotsTag = $robots !== '' ? "\n  <meta name=\"robots\" content=\"".htmlspecialchars($robots, ENT_QUOTES, 'UTF-8')."\" />" : '';

        $head = '<title>'.htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8')."</title>\n";
        $head .= '  <meta name="description" content="'.$description."\" />\n";
        $head .= '  <meta name="keywords" content="'.$keywords."\" />\n";
        $head .= '  <meta name="author" content="'.htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')."\" />\n";
        $head .= '  <link rel="canonical" href="'.$canonical."\" />\n";
        $head .= '  <meta property="og:type" content="website" />'."\n";
        $head .= '  <meta property="og:title" content="'.htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8')."\" />\n";
        $head .= '  <meta property="og:description" content="'.$description."\" />\n";
        $head .= '  <meta property="og:url" content="'.$canonical."\" />\n";
        if ($logoUrl !== '') {
            $head .= '  <meta property="og:image" content="'.$logoUrl."\" />\n";
        }
        $head .= $robotsTag;

        $structured = $this->structuredData($page, $config, $siteName, $siteUrl, $canonical, $logoUrl);
        if ($structured !== []) {
            $head .= "\n".'  <script type="application/ld+json">'.json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."</script>";
        }

        return $head;
    }

    /**
     * 生成页面正文快照（语义化 HTML，爬虫可直接读取）。
     */
    private function renderBody(array $page): string
    {
        return match ($page['type']) {
            'home' => $this->renderHomeBody($page['data'] ?? []),
            'products' => $this->renderProductsBody($page['data'] ?? []),
            'landing' => $this->renderLandingBody($page['data'] ?? []),
            'notices', 'help' => $this->renderArticleListBody($page['type'], $page['title'], $page['data']['articles'] ?? []),
            'notice_detail', 'help_detail' => $this->renderArticleDetailBody($page['data'] ?? []),
            'product_detail' => $this->renderProductDetailBody($page['data'] ?? []),
            default => $this->renderStaticBody($page['title']),
        };
    }

    private function renderHomeBody(array $data): string
    {
        $siteName = $this->siteName();
        $html = '<article class="seo-content">';
        $html .= '<header><h1>'.$siteName.' - 云服务器与 IDC 服务平台</h1>';
        $html .= '<p>提供云服务器、独立服务器、高防服务器、云电脑等产品，覆盖香港、美国与国内多地节点。</p></header>';

        $groups = $data['root_groups'] ?? [];
        if ($groups !== []) {
            $html .= '<section><h2>产品与方案</h2><ul>';
            foreach (array_slice($groups, 0, 12) as $group) {
                $name = (string) ($group['name'] ?? '');
                $slogan = (string) ($group['slogan'] ?? '');
                if ($name === '') {
                    continue;
                }
                $html .= '<li><strong>'.e($name).'</strong>'.($slogan !== '' ? '：'.e($slogan) : '').'</li>';
            }
            $html .= '</ul></section>';
        }

        $notices = $data['notices'] ?? [];
        if ($notices !== []) {
            $html .= '<section><h2>最新公告</h2><ul>';
            foreach (array_slice($notices, 0, 8) as $notice) {
                $title = (string) ($notice['title'] ?? '');
                $id = (int) ($notice['id'] ?? 0);
                if ($title === '' || $id <= 0) {
                    continue;
                }
                $html .= '<li><a href="/notices/'.$id.'">'.e($title).'</a>'
                    .($notice['publish_at'] ?? '' ? '（'.e((string) $notice['publish_at']).'）' : '').'</li>';
            }
            $html .= '</ul></section>';
        }

        $helps = $data['help_articles'] ?? [];
        if ($helps !== []) {
            $html .= '<section><h2>帮助文档</h2><ul>';
            foreach (array_slice($helps, 0, 6) as $help) {
                $title = (string) ($help['title'] ?? '');
                $id = (int) ($help['id'] ?? 0);
                if ($title === '' || $id <= 0) {
                    continue;
                }
                $html .= '<li><a href="/help/'.$id.'">'.e($title).'</a></li>';
            }
            $html .= '</ul></section>';
        }

        return $html.'</article>';
    }

    private function renderProductsBody(array $data): string
    {
        $html = '<article class="seo-content">';
        $html .= '<header><h1>产品与服务</h1>';
        $html .= '<p>'.$this->siteName().'提供云服务器、独立服务器、高防服务器、云电脑等 IDC 产品方案。</p></header>';

        $groups = $data['groups'] ?? [];
        if ($groups !== []) {
            $html .= '<section><h2>产品分类</h2><ul>';
            foreach (array_slice($groups, 0, 30) as $group) {
                $name = (string) ($group['name'] ?? '');
                $slogan = (string) ($group['slogan'] ?? '');
                $count = (int) ($group['product_count'] ?? 0);
                if ($name === '') {
                    continue;
                }
                $html .= '<li><strong>'.e($name).'</strong>'.($slogan !== '' ? '：'.e($slogan) : '')
                    .($count > 0 ? '（'.$count.' 款配置）' : '').'</li>';
            }
            $html .= '</ul></section>';
        }

        return $html.'</article>';
    }

    private function renderLandingBody(array $data): string
    {
        $landing = $data['landing'] ?? [];
        $heroTitle = (string) ($landing['hero_title'] ?? '');
        $heroSummary = (string) ($landing['hero_summary'] ?? '');
        $keyword = (string) ($landing['keyword'] ?? '');

        $html = '<article class="seo-content">';
        $html .= '<header><h1>'.e($heroTitle !== '' ? $heroTitle : $keyword).'</h1>';
        $html .= '<p>'.e($heroSummary).'</p></header>';

        $groups = $data['groups'] ?? [];
        if ($groups !== []) {
            $html .= '<section><h2>'.$keyword.'产品方案</h2><ul>';
            foreach (array_slice($groups, 0, 10) as $group) {
                $name = (string) ($group['name'] ?? '');
                $slogan = (string) ($group['slogan'] ?? '');
                if ($name === '') {
                    continue;
                }
                $html .= '<li><strong>'.e($name).'</strong>'.($slogan !== '' ? '：'.e($slogan) : '').'</li>';
            }
            $html .= '</ul></section>';
        }

        return $html.'</article>';
    }

    private function renderArticleListBody(string $linkPrefix, string $sectionTitle, array $articles): string
    {
        $html = '<article class="seo-content">';
        $html .= '<header><h1>'.e($sectionTitle).'</h1></header>';

        if ($articles === []) {
            return $html.'<p>暂无内容。</p></article>';
        }

        $html .= '<ul>';
        foreach ($articles as $article) {
            $title = (string) ($article['title'] ?? '');
            $id = (int) ($article['id'] ?? 0);
            $summary = (string) ($article['summary'] ?? '');
            if ($title === '' || $id <= 0) {
                continue;
            }
            $html .= '<li><h2><a href="/'.e($linkPrefix).'/'.$id.'">'.e($title).'</a></h2>'
                .($article['publish_at'] ?? '' ? '<time>'.e((string) $article['publish_at']).'</time>' : '')
                .($summary !== '' ? '<p>'.e($summary).'</p>' : '').'</li>';
        }
        $html .= '</ul>';

        return $html.'</article>';
    }

    private function renderArticleDetailBody(array $data): string
    {
        $html = '<article class="seo-content">';
        $html .= '<header><h1>'.e((string) ($data['title'] ?? '')).'</h1>';
        if (! empty($data['publish_at'])) {
            $html .= '<time>'.e((string) $data['publish_at']).'</time>';
        }
        if (! empty($data['category'])) {
            $html .= '<p>分类：'.e((string) $data['category']).'</p>';
        }
        $html .= '</header>';
        $html .= '<div class="seo-content-body">'.(string) ($data['content_html'] ?? '').'</div>';

        return $html.'</article>';
    }

    private function renderProductDetailBody(array $data): string
    {
        $html = '<article class="seo-content">';
        $html .= '<header><h1>'.e((string) ($data['name'] ?? '')).'</h1>';
        if (! empty($data['group_name'])) {
            $html .= '<p>'.e((string) $data['group_name']).'</p>';
        }
        if (! empty($data['spec'])) {
            $html .= '<p>规格：'.e((string) $data['spec']).'</p>';
        }
        if (! empty($data['price'])) {
            $html .= '<p>参考价格：'.e((string) $data['price']).' 元/'.e((string) ($data['cycle'] ?: '月')).'</p>';
        }
        $html .= '</header>';
        $html .= '<p>'.e((string) ($data['group_slogan'] ?? '')).'</p>';

        return $html.'</article>';
    }

    private function renderStaticBody(string $title): string
    {
        return '<article class="seo-content"><header><h1>'.e($title).'</h1></header>'
            .'<p>该页面内容由前端应用交互呈现，正文请以页面实际展示为准。</p></article>';
    }

    /**
     * 生成 JSON-LD 结构化数据。
     *
     * @return array<int, array<string, mixed>>
     */
    private function structuredData(array $page, array $config, string $siteName, string $siteUrl, string $canonical, string $logoUrl): array
    {
        $organizationId = $siteUrl.'/#organization';
        $websiteId = $siteUrl.'/#website';
        $webpageId = $canonical.'#webpage';

        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $siteName,
            'url' => $siteUrl.'/',
            'logo' => $logoUrl !== '' ? $logoUrl : null,
        ];

        $website = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'name' => $siteName,
            'url' => $siteUrl.'/',
            'inLanguage' => 'zh-CN',
            'publisher' => ['@id' => $organizationId],
        ];

        $webpage = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $webpageId,
            'url' => $canonical,
            'name' => $siteName.(($page['title'] ?? '') !== '' ? ' - '.(string) $page['title'] : ''),
            'description' => (string) ($page['description'] ?? ''),
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@id' => $websiteId],
        ];

        $structured = array_filter([$organization, $website], fn (array $item) => ($item['logo'] ?? null) !== null);

        if ($page['type'] === 'landing') {
            $landing = $page['data']['landing'] ?? [];
            $webpage['@type'] = 'Service';
            $webpage['headline'] = (string) ($landing['hero_title'] ?? '');
            $webpage['keywords'] = (string) ($landing['keywords'] ?? '');
            $webpage['about'] = [
                '@type' => 'Service',
                'name' => (string) ($landing['keyword'] ?? ''),
                'description' => (string) ($landing['hero_summary'] ?? ''),
                'provider' => ['@id' => $organizationId],
            ];
        }

        if ($page['type'] === 'notice_detail' || $page['type'] === 'help_detail') {
            $article = $page['data'] ?? [];
            $webpage = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                '@id' => $canonical.'#article',
                'url' => $canonical,
                'headline' => (string) ($article['title'] ?? ''),
                'description' => (string) ($article['summary'] ?? ''),
                'datePublished' => (string) ($article['publish_at'] ?? ''),
                'inLanguage' => 'zh-CN',
                'isPartOf' => ['@id' => $webpageId],
                'publisher' => ['@id' => $organizationId],
            ];
        }

        if ($page['type'] === 'product_detail') {
            $product = $page['data'] ?? [];
            $webpage = [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                '@id' => $canonical.'#product',
                'url' => $canonical,
                'name' => (string) ($product['name'] ?? ''),
                'description' => (string) ($page['description'] ?? ''),
                'brand' => ['@type' => 'Brand', 'name' => $siteName],
                'offers' => [
                    '@type' => 'Offer',
                    'url' => $canonical,
                    'price' => (string) ($product['price'] ?? '0'),
                    'priceCurrency' => 'CNY',
                    'availability' => 'https://schema.org/InStock',
                ],
            ];
        }

        $structured[] = $webpage;

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $siteUrl.'/'],
            ],
        ];

        $path = trim((string) ($page['canonical'] ?? ''), '/');
        if ($path !== '') {
            $breadcrumb['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => (string) ($page['title'] ?? ''),
                'item' => $canonical,
            ];
        }
        $structured[] = $breadcrumb;

        return $structured;
    }

    private function buildSitemapXml(): string
    {
        $siteUrl = $this->siteUrl();
        $now = now()->toDateString();
        $urls = [];

        $urls[] = ['loc' => $siteUrl.'/', 'lastmod' => $now, 'changefreq' => 'daily', 'priority' => '1.0'];
        $urls[] = ['loc' => $siteUrl.'/products', 'lastmod' => $now, 'changefreq' => 'weekly', 'priority' => '0.9'];
        $urls[] = ['loc' => $siteUrl.'/about', 'changefreq' => 'monthly', 'priority' => '0.6'];
        $urls[] = ['loc' => $siteUrl.'/terms', 'changefreq' => 'yearly', 'priority' => '0.3'];
        $urls[] = ['loc' => $siteUrl.'/privacy', 'changefreq' => 'yearly', 'priority' => '0.3'];
        $urls[] = ['loc' => $siteUrl.'/notices', 'changefreq' => 'weekly', 'priority' => '0.6'];
        $urls[] = ['loc' => $siteUrl.'/help', 'changefreq' => 'weekly', 'priority' => '0.6'];

        foreach (SeoLandingPages::all() as $page) {
            $urls[] = [
                'loc' => $siteUrl.$page['path'],
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        foreach ([ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP] as $articleType) {
            $articles = $this->contentArticleService->publishedList($articleType, [], 500)->items();
            $prefix = $articleType === ContentArticle::TYPE_NOTICE ? 'notices' : 'help';
            foreach ($articles as $article) {
                $urls[] = [
                    'loc' => $siteUrl.'/'.$prefix.'/'.(int) $article->id,
                    'lastmod' => optional($article->updated_at ?? $article->publish_at)?->toDateString() ?: $now,
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        }

        $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url>'
                .'<loc>'.e((string) $url['loc']).'</loc>'
                .(isset($url['lastmod']) ? '<lastmod>'.e((string) $url['lastmod']).'</lastmod>' : '')
                .'<changefreq>'.e((string) $url['changefreq']).'</changefreq>'
                .'<priority>'.e((string) $url['priority']).'</priority>'
                .'</url>';
        }

        return $xml.'</urlset>';
    }

    /**
     * @param  array<int, ContentArticle>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function transformArticles(array $articles): array
    {
        return collect($articles)
            ->map(function (ContentArticle $article): array {
                $excerpt = trim((string) $article->summary);
                if ($excerpt === '') {
                    $excerpt = Str::limit(
                        preg_replace('/\s+/u', ' ', strip_tags(Str::markdown((string) $article->content))),
                        120,
                        '...'
                    );
                }

                return [
                    'id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'summary' => $excerpt,
                    'publish_at' => optional($article->publish_at)?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }

    private function sanitizeHtml(string $html): string
    {
        return (string) strip_tags($html, [
            'p', 'br', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'em', 'b', 'i', 'a', 'pre', 'code', 'blockquote',
            'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'hr',
        ]);
    }

    private function siteName(?array $config = null): string
    {
        $config ??= SiteConfigPayload::payload();

        return (string) ($config['browser_title'] ?? $config['site_name'] ?? SiteConfigPayload::DEFAULT_SITE_NAME);
    }

    private function siteUrl(): string
    {
        return rtrim((string) config('idc.seo.site_url', ''), '/');
    }

    private function absoluteAssetUrl(string $siteUrl, string $asset): string
    {
        if ($asset === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $asset)) {
            return $asset;
        }

        return $siteUrl.'/'.ltrim($asset, '/');
    }
}
