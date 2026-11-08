<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceConsoleAssetService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 自定义面板 HTML 改写的安全/兼容契约回归。
 *
 * 背景：上游 provision/custom/content 返回的是「半页」HTML 片段，它有两个问题：
 *   1. 片段自身的 ajax() 里嵌着我们传进去的 now_jwt（转售 API 凭据，约 2 小时有效），
 *      随 iframe 下发给每个终端用户即等于泄露上游账号；
 *   2. 片段假定父页面提供 jQuery / Bootstrap 4 / SweetAlert2，我们的 iframe 复不到，
 *      缺运行时就会刷 "jQuery is not defined" / "$ is not defined"。
 *
 * 这里锁定 rewriteModulePage() 的输入输出口径（只测私有实现的输入输出，不连上游）：
 *   - 完整删掉 Authorization 请求头和上游脚本，避免同源 iframe 执行供应商内容；
 *   - 删除上游可执行内容并注入同源本地运行时，CSP 可关闭 inline/eval。
 *
 * 继承裸 PHPUnit TestCase：不启动 Laravel、不连数据库，可在任何环境安全执行。
 */
class ClientServiceConsoleAreaPanelRewriteTest extends TestCase
{
    /** 与上游真实片段同形的样本：两个 ajax 分支各嵌一处凭据 + 旧版 Swal 用法 */
    private function panelFragment(): string
    {
        return <<<'HTML'
        <link rel="stylesheet" href="https://ww.stay33.cn/vendor/dcimcloud/css/01setting.css">
        <div class="mountingISO"><button id="mountingISOBtn">确定</button></div>
        <script src="https://ww.stay33.cn/vendor/dcimcloud/js/selectFilter.js"></script>
        <script src="//ww.stay33.cn/vendor/dcimcloud/js/selectFilter-2.js"></script>
        <script>
          $('.iso-box').selectFilter({ callBack: function (val) {} });
          Swal.fire({ title: '确定要挂载选择的ISO吗？', type: 'question', showCancelButton: true })
            .then((result) => {
              if (result.value) {
                ajax({ type: "post", url: "{$MODULE_CUSTOM_API}", data: { "func": "mountIso" } });
                ajax({ type: "get", url: "https://ww.stay33.cn/provision/custom/68873", data: { "func": "listIso" } });
              }
            });
          Swal.fire("挂载失败", data.msg, "error");
          function ajax(options) {
            var xhr = new XMLHttpRequest();
            if (options.type == "get") {
              var url = options.url + "?" + str;
              xhr.open("get", url);
              xhr.setRequestHeader("Authorization", "JWT eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyaW5mbyI6e319.abc");
              xhr.send();
            } else if (options.type == "post") {
              xhr.open("post", options.url);
              xhr.setRequestHeader("content-type", "application/x-www-form-urlencoded");
              xhr.setRequestHeader("Authorization", "JWT eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyaW5mbyI6e319.abc");
              xhr.send(str)
            }
          }
        </script>
        HTML;
    }

    #[Test]
    public function embedded_reseller_credentials_and_upstream_scripts_are_removed(): void
    {
        $out = $this->rewrite($this->panelFragment());

        $this->assertStringNotContainsString('eyJ', $out, '面板 HTML 不得残留任何 JWT 字面量');
        $this->assertStringNotContainsString('Authorization', $out, 'Authorization 请求头语句必须整条删除');
        $this->assertSame(
            [],
            $this->authorizationHeaders($out),
            '只允许保留 content-type 头，Authorization 一律不得存在'
        );

        $this->assertStringNotContainsString('selectFilter.js', $out, '上游脚本资源不得继续下发');
        $this->assertStringNotContainsString('alert(', $out, '上游脚本正文不能残留');
        $this->assertStringContainsString('mountingISO', $out, '非可执行面板内容应保留');
        // 动作地址仍在服务端改写后再被脚本净化，不应泄露未改写的上游模板变量。
        $this->assertStringNotContainsString('{$MODULE_CUSTOM_API}', $out);
    }

    #[Test]
    public function panel_fragment_is_wrapped_with_local_runtime_without_inline_scripts(): void
    {
        $out = $this->rewrite($this->panelFragment());

        foreach ([
            'jquery.min.js',
            'bootstrap.min.css',
            'bootstrap.bundle.min.js',
            'sweetalert2.all.min.js',
            'panel-runtime.js',
        ] as $asset) {
            $this->assertStringContainsString(
                '/media/console-panel/'.$asset,
                $out,
                '面板必须自带本地运行时：'.$asset
            );
        }

        $this->assertStringStartsWith('<!DOCTYPE html>', $out, '片段应被包成完整文档');
        $this->assertStringContainsString('<body>', $out);
        $this->assertStringContainsString('</html>', $out);

        $this->assertDoesNotMatchRegularExpression('~<script(?![^>]+\bsrc=)[^>]*>~i', $out);
        $this->assertStringNotContainsString('window.Swal.fire = function', $out);
    }

    /**
     * 运行时基路径下的 4 个静态资产必须真实存在于磁盘。
     *
     * 事故背景：基路径从 /vendor/console-panel 迁到 /media/console-panel 时，只改了注入
     * HTML 的前缀，资产本身还留在 vendor 目录下。生产上 4 个文件全部 404，面板里每一处
     * $(...) 都抛 "$ is not defined" —— 而本文件其余断言只检查生成的 HTML 字符串，
     * 对「资产到底有没有入库」完全无感，所以测试全绿、线上照挂。
     *
     * 基路径从常量派生而不是写死，改了常量忘了搬文件时这条会立刻红。
     */
    #[Test]
    public function runtime_assets_really_exist_under_the_runtime_base_path(): void
    {
        $assetDir = dirname(__DIR__, 2).'/public'.ServiceConsoleAreaService::RUNTIME_ASSET_BASE;

        $this->assertDirectoryExists($assetDir, '面板运行时资源目录必须随基路径一起迁移：'.$assetDir);

        foreach ([
            'bootstrap.min.css',
            'jquery.min.js',
            'bootstrap.bundle.min.js',
            'sweetalert2.all.min.js',
            'panel-runtime.js',
        ] as $asset) {
            $path = $assetDir.'/'.$asset;

            $this->assertFileExists($path, '面板运行时资产缺失，浏览器会直接拿到 404：'.$path);
            $this->assertGreaterThan(0, (int) @filesize($path), '面板运行时资产不能是空文件：'.$path);
        }
    }

    #[Test]
    public function upstream_asset_urls_are_replaced_by_the_local_proxy(): void
    {
        $out = $this->rewrite($this->panelFragment());

        $this->assertStringNotContainsString(
            'stay33.cn',
            $out,
            '下发给终端用户的面板 HTML 不得出现上游域名（否则用户可直接扒出上游地址）'
        );
        $this->assertStringNotContainsString('selectFilter.js', $out, '上游脚本标签必须在净化阶段删除');

        foreach ([
            '/vendor/dcimcloud/css/01setting.css',
        ] as $path) {
            $this->assertStringContainsString(
                'asset?path='.rawurlencode($path),
                $out,
                '上游静态资源应改走本系统反代：'.$path
            );
        }
    }

    #[Test]
    public function asset_proxy_only_accepts_plain_paths(): void
    {
        // 只接受「以 / 开头的路径」，任何自带协议/主机/上级目录的输入都必须拒绝，
        // 否则本端点会变成可以打任意地址的开放反代。
        foreach ([
            'https://evil.example.com/x.css',
            '//evil.example.com/x.css',
            '/../etc/passwd',
            '/vendor/../../etc/passwd',
            '',
            '/vendor/x.css?x=1<script>',
            "/vendor/x.css\nHost: evil",
        ] as $unsafe) {
            $rejected = false;

            try {
                $this->assertSafeAssetPath($unsafe);
            } catch (BusinessException $exception) {
                $rejected = true;
            }

            $this->assertTrue($rejected, '非路径形态的输入必须被拒绝：'.json_encode($unsafe, JSON_UNESCAPED_UNICODE));
        }

        // 正常路径放行（含查询串，面板会带版本号）
        $this->assertSame('/vendor/dcimcloud/css/01setting.css', $this->assertSafeAssetPath('/vendor/dcimcloud/css/01setting.css'));
        $this->assertSame('/vendor/a.css?v=2', $this->assertSafeAssetPath('/vendor/a.css?v=2'));
    }

    #[Test]
    public function upstream_asset_targets_reject_non_http_and_non_public_addresses(): void
    {
        foreach ([
            'file:///etc/passwd',
            'ftp://8.8.8.8/resource.css',
            'http://127.0.0.1/resource.css',
            'http://10.0.0.1/resource.css',
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost/resource.css',
            'https://[::1]/resource.css',
            'https://[::ffff:127.0.0.1]/resource.css',
            'https://[fe80::1]/resource.css',
            'https://[fc00::1]/resource.css',
            'https://[ff02::1]/resource.css',
            'https://[2001:3::1]/resource.css',
            'https://[2001:4:112::1]/resource.css',
            'https://[2001:db8::1]/resource.css',
            'https://8.8.8.8:0/resource.css',
            'https://8.8.8.8:65536/resource.css',
        ] as $unsafe) {
            $rejected = false;

            try {
                $this->assertSafeUpstreamUrl($unsafe);
            } catch (BusinessException $exception) {
                $rejected = true;
            }

            $this->assertTrue($rejected, '上游资源目标必须拒绝：'.$unsafe);
        }

        $safe = $this->assertSafeUpstreamUrl('https://8.8.8.8:8443/resource.css');
        $this->assertSame(8443, $safe['port']);
        $this->assertSame('8.8.8.8', $safe['ip']);
        $this->assertTrue($safe['is_ip']);

        $safeV6 = $this->assertSafeUpstreamUrl('https://[2001:4860:4860::8888]/resource.css');
        $this->assertSame('2001:4860:4860::8888', $safeV6['ip']);
        $this->assertSame(443, $safeV6['port']);
    }

    #[Test]
    public function redirect_locations_are_resolved_without_leaving_the_validated_origin_unnoticed(): void
    {
        $base = 'https://assets.example.com/vendor/css/select.css';

        $this->assertSame(
            'https://assets.example.com/vendor/img/icon.png',
            $this->resolveRedirectUrl($base, '../img/icon.png')
        );
        $this->assertSame(
            'https://cdn.example.com/icon.png',
            $this->resolveRedirectUrl($base, '//cdn.example.com/icon.png')
        );
        $this->assertSame(
            'https://assets.example.com/vendor/css/select.css?v=2',
            $this->resolveRedirectUrl($base, '?v=2')
        );
        $this->assertSame($base, $this->resolveRedirectUrl($base, '#icon'));

        $this->assertSame(
            'http://127.0.0.1/private',
            $this->resolveRedirectUrl($base, 'http://127.0.0.1/private')
        );

        $rejected = false;
        try {
            $this->assertSafeUpstreamUrl($this->resolveRedirectUrl($base, 'http://127.0.0.1/private'), 'assets.example.com', 'https');
        } catch (BusinessException $exception) {
            $rejected = true;
        }
        $this->assertTrue($rejected, '重定向目标必须经过同样的公网地址校验');

        foreach ([
            ['https://cdn.example.com/icon.png', '跨主机跳转'],
            ['http://assets.example.com/icon.png', 'HTTPS 降级跳转'],
            ['https://assets.example.com:8443/icon.png', '端口变更跳转'],
        ] as [$redirect, $label]) {
            $rejected = false;
            try {
                $this->assertSafeUpstreamUrl($redirect, 'assets.example.com', 'https', 443);
            } catch (BusinessException $exception) {
                $rejected = true;
            }
            $this->assertTrue($rejected, $label.'必须拒绝');
        }

        $upgrade = $this->assertSafeUpstreamUrl('https://8.8.8.8/icon.png', '8.8.8.8', 'http', 80);
        $this->assertSame('8.8.8.8', $upgrade['host']);
        $this->assertSame(443, $upgrade['port']);
    }

    #[Test]
    public function select_filter_stylesheet_is_injected_through_the_proxy(): void
    {
        $out = $this->rewrite($this->panelFragment());

        $expected = 'asset?path='.rawurlencode('/vendor/dcimcloud/css/selectFilter.css');

        $this->assertStringContainsString(
            $expected,
            $out,
            '片段只带了 selectFilter.js，配套的 selectFilter.css（原本由父页面提供）必须补上并走反代'
        );
        // 注入在 <head>：运行时之后、<body> 之前，避免下拉框先按原生样式渲染
        $this->assertLessThan(strpos($out, '<body>'), strpos($out, $expected));
        $this->assertStringNotContainsString('stay33.cn', $out);
    }

    #[Test]
    public function select_filter_stylesheet_is_not_injected_twice(): void
    {
        $fragment = str_replace(
            '<div class="mountingISO">',
            '<link rel="stylesheet" href="https://ww.stay33.cn/vendor/dcimcloud/css/selectFilter.css"><div class="mountingISO">',
            $this->panelFragment()
        );

        $out = $this->rewrite($fragment);

        $this->assertSame(1, substr_count($out, 'selectFilter.css'), '面板已自带该样式表时不应重复注入');
    }

    /**
     * 上游偶尔直接返回完整文档（如 CDN 的「管理面板」），且把自带的 Bootstrap 注释掉了，
     * 同样指望父页面提供样式 —— 不补运行时就是「一条样式都没有」。
     */
    private function fullDocumentFragment(string $extraHead = ''): string
    {
        return '<!DOCTYPE html>'."\n"
            .'<html lang="en">'."\n"
            .'<head>'."\n"
            .'    <meta charset="UTF-8">'."\n"
            .$extraHead
            .'    <title>Document</title>'."\n"
            .'</head>'."\n"
            .'<body>'."\n"
            .'    <div class="card bg-primary text-white"><div class="card-body">ser216433334213@qq.com</div></div>'."\n"
            .'    <a href="https://www.heicdn.cn/#/auth-redirect?action=jwt_login&amp;token=abc" class="btn btn-primary btn-block">登录面板</a>'."\n"
            .'</body>'."\n"
            .'</html>';
    }

    #[Test]
    public function full_document_fragment_gets_the_runtime_injected_into_its_head(): void
    {
        $out = $this->rewrite($this->fullDocumentFragment());

        $this->assertStringContainsString('/media/console-panel/bootstrap.min.css', $out, 'CDN 这类完整文档同样需要 Bootstrap 样式');
        $this->assertStringContainsString('/media/console-panel/jquery.min.js', $out);

        // 插在 charset 之后、</head> 之前，且不把文档套壳成两份
        $charsetAt = strpos($out, 'charset="UTF-8"');
        $cssAt = strpos($out, '/media/console-panel/bootstrap.min.css');
        $this->assertGreaterThan($charsetAt, $cssAt, '运行时不能插在 charset 之前');
        $this->assertLessThan(strpos($out, '</head>'), $cssAt);
        $this->assertSame(1, substr_count($out, '<!DOCTYPE html>'), '完整文档不能被二次套壳');
        $this->assertSame(1, substr_count($out, '<body>'));

        // 片段自身内容与外部跳转链接保持原样
        $this->assertStringContainsString('ser216433334213@qq.com', $out);
        $this->assertStringContainsString('https://www.heicdn.cn/#/auth-redirect', $out);
    }

    #[Test]
    public function full_document_external_scripts_are_removed_and_local_bundle_is_used(): void
    {
        $out = $this->rewrite($this->fullDocumentFragment(
            '    <script src="https://cdn.example.com/jquery-3.6.0.min.js"></script>'."\n"
        ));

        $this->assertStringContainsString('/media/console-panel/jquery.min.js', $out, '外部脚本被清理后必须使用本站运行时');
        $this->assertStringNotContainsString('cdn.example.com/jquery', $out, '任意外部脚本不得进入面板');
        $this->assertStringContainsString('/media/console-panel/bootstrap.min.css', $out, '样式仍然要补');
    }

    /**
     * 上游 CSS 里的相对 url() 必须重新指向本站反代。
     *
     * 事故背景：跨源反代换掉了相对引用的解析基准。上游
     *   /vendor/dcimcloud/css/selectFilter.css 里写的是 url(../img/icon_arrow_down.png)，
     *   本应解析成 /vendor/dcimcloud/img/icon_arrow_down.png；经 ?path= 形式的反代 URL 加载后，
     *   基准目录变成反代端点所在目录，最终请求到本站 /api/v2/client/services/{id}/img/... → 404，
     *   下拉框箭头空白。根因是反代把上游资源原样透传，没有处理资源内部的相对引用。
     */
    #[Test]
    public function css_relative_urls_are_repointed_to_the_local_proxy(): void
    {
        $out = $this->rewriteCss(
            '.sel-arrow { background-image: url(../img/icon_arrow_down.png); }',
            '/vendor/dcimcloud/css/selectFilter.css'
        );

        $this->assertStringContainsString(
            'url('.$this->assetProxyUrl(3915, 'TESTTICKET', '/vendor/dcimcloud/img/icon_arrow_down.png').')',
            $out,
            'CSS 内的相对 url() 必须改写成本站反代地址'
        );
    }

    /** 根相对 url() 按 CSS 自身所在目录解析（CSS 规范如此），查询串要跟着路径一起走。 */
    #[Test]
    public function css_root_relative_and_queried_urls_follow_the_stylesheet_directory(): void
    {
        $out = $this->rewriteCss(
            'a{background:url(/img/x.png)} b{background:url(../img/y.png?v=3)}',
            '/vendor/dcimcloud/css/selectFilter.css'
        );

        $this->assertStringContainsString(
            'url('.$this->assetProxyUrl(3915, 'TESTTICKET', '/vendor/dcimcloud/css/img/x.png').')',
            $out,
            '根相对 url() 相对样式表目录解析'
        );
        $this->assertStringContainsString(
            'url('.$this->assetProxyUrl(3915, 'TESTTICKET', '/vendor/dcimcloud/img/y.png?v=3').')',
            $out,
            '查询串必须跟在路径后面一起编码，不能被当作路径段'
        );
    }

    #[Test]
    public function css_non_relative_and_escaping_urls_are_left_untouched(): void
    {
        $css = 'a{background:url(data:image/gif;base64,R0lGOD)}'
            .'b{background:url(https://cdn.example.com/x.png)}'
            .'c{background:url(//cdn.example.com/y.png)}'
            .'d{mask:url(#icon)}'
            .'e{background:url("../../../../../../etc/passwd")}';

        $out = $this->rewriteCss($css, '/vendor/dcimcloud/css/a.css');

        foreach ([
            'url(data:image/gif;base64,R0lGOD)',
            'url(https://cdn.example.com/x.png)',
            'url(//cdn.example.com/y.png)',
            'url(#icon)',
            'url("../../../../../../etc/passwd")',
        ] as $kept) {
            $this->assertStringContainsString($kept, $out, '非相对引用（或越出站点根）必须原样保留：'.$kept);
        }
    }

    /**
     * JS 里的相对路径没有可枚举的可靠形态（字符串拼接、new Image().src 等），
     * 因此只改写 CSS；这里锁住「JS 原样透传」这个边界，防止有人日后用正则去改 JS。
     */
    #[Test]
    public function non_stylesheet_assets_are_passed_through_verbatim(): void
    {
        $js = 'var icon = "../img/icon_arrow_down.png"; new Image().src = "../img/a.png";';

        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'prepareResponse');
        $method->setAccessible(true);

        $out = $method->invoke(
            new ServiceConsoleAssetService,
            ['body' => $js, 'content_type' => 'application/javascript; charset=UTF-8'],
            '/vendor/dcimcloud/js/selectFilter.js',
            fn (string $p): string => $this->assetProxyUrl(3915, 'TESTTICKET', $p)
        );

        $this->assertSame($js, $out['body'], '非样式表资源不得改写内容');
    }

    /**
     * 反代端点前缀与 routes/v2-client.php 的 console-area/asset 路由必须一致。
     * 路由未具名，这里手写了前缀，用本用例锁住两边不漂移。
     */
    #[Test]
    public function asset_proxy_url_matches_the_client_route(): void
    {
        $url = $this->assetProxyUrl(3915, 'TICKET+1', '/vendor/dcimcloud/img/icon_arrow_down.png');

        $this->assertStringStartsWith(
            '/api/v2/client/services/3915/console-area/asset?',
            $url,
            '反代地址前缀必须与 routes/v2-client.php 的 console-area/asset 路由一致'
        );
        $this->assertStringNotContainsString('ticket=', $url, '票据不得进入资源代理 URL');
        $this->assertStringContainsString(
            'path='.rawurlencode('/vendor/dcimcloud/img/icon_arrow_down.png'),
            $url
        );
    }

    /** 直接驱动反代服务的 CSS 改写（私有实现，不发起任何网络请求）。 */
    private function rewriteCss(string $css, string $resourcePath): string
    {
        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'rewriteCssRelativeUrls');
        $method->setAccessible(true);

        return (string) $method->invoke(
            new ServiceConsoleAssetService,
            $css,
            $resourcePath,
            fn (string $p): string => $this->assetProxyUrl(3915, 'TESTTICKET', $p)
        );
    }

    /** 直接驱动本站反代地址的拼装（私有实现）。 */
    private function assetProxyUrl(int $serviceId, string $ticket, string $upstreamPath): string
    {
        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveSupplierRootUrl'])
            ->getMock();

        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'assetProxyUrl');
        $method->setAccessible(true);

        return (string) $method->invoke(
            new ServiceConsoleAreaService($detailService, new ServiceConsoleAssetService),
            $serviceId,
            $ticket,
            $upstreamPath
        );
    }

    /** 直接驱动反代服务的路径校验（私有实现，不发起任何网络请求）。 */
    private function assertSafeAssetPath(string $path): string
    {
        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'assertSafePath');
        $method->setAccessible(true);

        return (string) $method->invoke(new ServiceConsoleAssetService, $path);
    }

    /** 直接驱动每跳回源地址校验，不发起网络请求。 */
    private function assertSafeUpstreamUrl(string $url, string $expectedHost = '', string $expectedScheme = '', int $expectedPort = 0): array
    {
        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'assertSafeUpstreamUrl');
        $method->setAccessible(true);

        /** @var array{host: string, port: int, ip: string, is_ip: bool} $target */
        $target = $method->invoke(new ServiceConsoleAssetService, $url, $expectedHost, $expectedScheme, $expectedPort);

        return $target;
    }

    /** 直接驱动跳转地址解析，不发起网络请求。 */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'resolveRedirectUrl');
        $method->setAccessible(true);

        return (string) $method->invoke(new ServiceConsoleAssetService, $baseUrl, $location);
    }

    /**
     * @return array<int, string> 输出中保留的 Authorization 头取值
     */
    private function authorizationHeaders(string $html): array
    {
        preg_match_all('~setRequestHeader\(([^)]*)\)~i', $html, $matches);

        return array_values(array_filter(
            array_map('trim', $matches[1] ?? []),
            fn (string $header): bool => stripos($header, 'Authorization') !== false
        ));
    }

    /** 直接驱动私有实现：本用例只关心「片段 → 改写结果」的输入输出口径。 */
    private function rewrite(string $fragment): string
    {
        // rewriteModulePage 是 iframe 取数的唯一改写点：URL 改写 → 剥离凭据 → 注入运行时。
        // 用桩替掉 detailService，只为让它能解析出供应商根域名，其余逻辑走真实实现。
        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveSupplierRootUrl'])
            ->getMock();
        $detailService->method('resolveSupplierRootUrl')->willReturn('https://ww.stay33.cn');

        $service = new ServiceConsoleAreaService($detailService, new ServiceConsoleAssetService);
        $supplier = (new ReflectionClass(Supplier::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'rewriteModulePage');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $fragment, 68873, $supplier, 'TESTTICKET');
    }
}
