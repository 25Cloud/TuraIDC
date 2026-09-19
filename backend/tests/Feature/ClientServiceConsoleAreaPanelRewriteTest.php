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
 * 自定义面板 HTML 改写的两条安全/兼容契约回归。
 *
 * 背景：上游 provision/custom/content 返回的是「半页」HTML 片段，它有两个问题：
 *   1. 片段自身的 ajax() 里嵌着我们传进去的 now_jwt（转售 API 凭据，约 2 小时有效），
 *      随 iframe 下发给每个终端用户即等于泄露上游账号；
 *   2. 片段假定父页面提供 jQuery / Bootstrap 4 / SweetAlert2，我们的 iframe 复不到，
 *      缺运行时就会刷 "jQuery is not defined" / "$ is not defined"。
 *
 * 这里锁定 rewriteModulePage() 两步改写的口径（只测私有实现的输入输出，不连上游）：
 *   - 完整删掉 Authorization 请求头语句，其余 ajax 逻辑不受影响；
 *   - 注入本地运行时并包成完整文档，且运行时必须在片段内联脚本之前。
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
    public function embedded_reseller_credentials_are_removed_but_ajax_logic_survives(): void
    {
        $out = $this->rewrite($this->panelFragment());

        $this->assertStringNotContainsString('eyJ', $out, '面板 HTML 不得残留任何 JWT 字面量');
        $this->assertStringNotContainsString('Authorization', $out, 'Authorization 请求头语句必须整条删除');
        $this->assertSame(
            [],
            $this->authorizationHeaders($out),
            '只允许保留 content-type 头，Authorization 一律不得存在'
        );

        // 剥离只针对凭据，动作回发逻辑必须保持可用
        $this->assertStringContainsString('xhr.open("post", options.url)', $out);
        $this->assertStringContainsString('content-type', $out);
        $this->assertStringContainsString('xhr.send(str)', $out);
        // URL 改写（同一次调用里先执行）仍要正常：模板变量与上游绝对地址都换成票据代理地址
        $this->assertStringNotContainsString('{$MODULE_CUSTOM_API}', $out);
        $this->assertSame(
            2,
            preg_match_all('~url:\s*"actions\?ticket=TESTTICKET"~', $out),
            '两处面板动作地址都应改写成本地票据代理地址'
        );
    }

    #[Test]
    public function panel_fragment_is_wrapped_with_local_runtime_before_inline_scripts(): void
    {
        $out = $this->rewrite($this->panelFragment());

        foreach ([
            'jquery.min.js',
            'bootstrap.min.css',
            'bootstrap.bundle.min.js',
            'sweetalert2.all.min.js',
        ] as $asset) {
            $this->assertStringContainsString(
                '/vendor/console-panel/'.$asset,
                $out,
                '面板必须自带本地运行时：'.$asset
            );
        }

        $this->assertStringStartsWith('<!DOCTYPE html>', $out, '片段应被包成完整文档');
        $this->assertStringContainsString('<body>', $out);
        $this->assertStringContainsString('</html>', $out);

        // 运行时脚本必须排在片段内联脚本之前，否则仍会报 $ is not defined
        $this->assertLessThan(
            strpos($out, 'function ajax(options)'),
            strpos($out, 'sweetalert2.all.min.js'),
            '运行时脚本必须先于片段内联脚本解析'
        );

        // SweetAlert2 v11 不收旧版 type: 参数，垫片把 type 映射成 icon
        $this->assertStringContainsString('window.Swal.fire = function', $out);
        $this->assertStringContainsString("args[0].icon = args[0].type", $out);
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

        foreach ([
            '/vendor/dcimcloud/css/01setting.css',
            '/vendor/dcimcloud/js/selectFilter.js',
            '/vendor/dcimcloud/js/selectFilter-2.js',
        ] as $path) {
            $this->assertStringContainsString(
                'asset?ticket=TESTTICKET&path='.rawurlencode($path),
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
            } catch (BusinessException) {
                $rejected = true;
            }

            $this->assertTrue($rejected, '非路径形态的输入必须被拒绝：'.json_encode($unsafe, JSON_UNESCAPED_UNICODE));
        }

        // 正常路径放行（含查询串，面板会带版本号）
        $this->assertSame('/vendor/dcimcloud/css/01setting.css', $this->assertSafeAssetPath('/vendor/dcimcloud/css/01setting.css'));
        $this->assertSame('/vendor/a.css?v=2', $this->assertSafeAssetPath('/vendor/a.css?v=2'));
    }

    #[Test]
    public function select_filter_stylesheet_is_injected_through_the_proxy(): void
    {
        $out = $this->rewrite($this->panelFragment());

        $expected = 'asset?ticket=TESTTICKET&path='.rawurlencode('/vendor/dcimcloud/css/selectFilter.css');

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

        $this->assertStringContainsString('/vendor/console-panel/bootstrap.min.css', $out, 'CDN 这类完整文档同样需要 Bootstrap 样式');
        $this->assertStringContainsString('/vendor/console-panel/jquery.min.js', $out);

        // 插在 charset 之后、</head> 之前，且不把文档套壳成两份
        $charsetAt = strpos($out, 'charset="UTF-8"');
        $cssAt = strpos($out, 'vendor/console-panel/bootstrap.min.css');
        $this->assertGreaterThan($charsetAt, $cssAt, '运行时不能插在 charset 之前');
        $this->assertLessThan(strpos($out, '</head>'), $cssAt);
        $this->assertSame(1, substr_count($out, '<!DOCTYPE html>'), '完整文档不能被二次套壳');
        $this->assertSame(1, substr_count($out, '<body>'));

        // 片段自身内容与外部跳转链接保持原样
        $this->assertStringContainsString('ser216433334213@qq.com', $out);
        $this->assertStringContainsString('https://www.heicdn.cn/#/auth-redirect', $out);
    }

    #[Test]
    public function full_document_with_its_own_jquery_keeps_our_script_bundle_out(): void
    {
        $out = $this->rewrite($this->fullDocumentFragment(
            '    <script src="https://cdn.example.com/jquery-3.6.0.min.js"></script>'."\n"
        ));

        $this->assertStringNotContainsString(
            '/vendor/console-panel/jquery.min.js',
            $out,
            '片段自带 jQuery 时不能再注入一份，否则会冲掉前一份的插件注册'
        );
        $this->assertStringContainsString('/vendor/console-panel/bootstrap.min.css', $out, '样式仍然要补');
    }

    /** 直接驱动反代服务的路径校验（私有实现，不发起任何网络请求）。 */
    private function assertSafeAssetPath(string $path): string
    {
        $method = new ReflectionMethod(ServiceConsoleAssetService::class, 'assertSafePath');
        $method->setAccessible(true);

        return (string) $method->invoke(new ServiceConsoleAssetService, $path);
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

        $service = new ServiceConsoleAreaService($detailService, new ServiceConsoleAssetService());
        $supplier = (new ReflectionClass(Supplier::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'rewriteModulePage');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $fragment, 68873, $supplier, 'TESTTICKET');
    }
}
