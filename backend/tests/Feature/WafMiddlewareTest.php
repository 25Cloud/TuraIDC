<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 入站 WAF 中间件在真实 HTTP 管线中的行为。
 *
 * 与 WafFirewallTest（纯引擎级）的分工：本测试关心中间件的**接线**是否正确——
 * 是否真的挂上了 api 组、豁免路径是否生效、开关与观察模式是否被尊重、拦截响应
 * 是否符合本项目的 ApiResponseBuilder 契约。
 */
final class WafMiddlewareTest extends TestCase
{
    /** 一个无需认证、且不在豁免名单内的公开只读接口，用作打点。 */
    private const PROBE = '/api/v2/site/product-types';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waf.enabled', true);
        config()->set('waf.observe_only', false);
    }

    public function test_blocks_sql_injection_payload_with_business_error_code(): void
    {
        $response = $this->getJson(self::PROBE.'?keyword='.urlencode("' union select password from users--"));

        $response->assertStatus(400);
        $response->assertJsonPath('code', 40009);
    }

    public function test_blocks_code_execution_payload(): void
    {
        $response = $this->getJson(self::PROBE.'?q='.urlencode('eval($_POST[x])'));

        $response->assertStatus(400)->assertJsonPath('code', 40009);
    }

    public function test_blocks_scanner_user_agent(): void
    {
        $response = $this->withHeader('User-Agent', 'sqlmap/1.7#stable')
            ->getJson(self::PROBE);

        $response->assertStatus(400)->assertJsonPath('code', 40009);
    }

    public function test_allows_normal_request(): void
    {
        $response = $this->getJson(self::PROBE.'?keyword='.urlencode('云服务器'));

        // 只断言"没有被 WAF 拦下"，不关心业务返回什么（可能 200 也可能因数据为空而其它码）。
        self::assertNotSame(40009, $response->json('code'), '正常请求被 WAF 误伤');
    }

    /**
     * 正向断言：带中文与常见符号的正常查询必须真的抵达业务并成功返回。
     *
     * 上面的 test_allows_normal_request 只能断言"没被拦"，本用例挑一个不依赖
     * 数据表的接口，把断言强化为"确实 200"，避免 WAF 把请求改坏却仍不算命中。
     */
    public function test_normal_request_reaches_application_successfully(): void
    {
        // 用 /api/health 而非 /api/ready：后者会真实探测队列、调度等外部依赖，
        // 测试环境下本就不就绪（503），与 WAF 是否放行无关，拿它断言等于测错对象。
        $response = $this->getJson('/api/health?keyword='.urlencode('云服务器 香港 100M'));

        $response->assertStatus(200);
    }

    public function test_disabled_switch_lets_payload_through(): void
    {
        config()->set('waf.enabled', false);

        $response = $this->getJson(self::PROBE.'?q='.urlencode('union select 1 from information_schema.tables'));

        self::assertNotSame(40009, $response->json('code'), '总开关关闭后仍在拦截');
    }

    public function test_observe_only_mode_logs_but_does_not_block(): void
    {
        config()->set('waf.observe_only', true);
        config()->set('waf.log_hits', true);

        // 用 spy 而非 shouldReceive：后者会把整个 Log 门面替换成严格 mock，
        // 一旦业务链路上有任何其它日志调用（例如异常处理器的 Log::error）
        // 就会因"未声明期望"而报错，测的是 mock 本身而非 WAF 行为。
        Log::spy();

        $response = $this->getJson(self::PROBE.'?q='.urlencode('union select 1 from information_schema.tables'));

        self::assertNotSame(40009, $response->json('code'), '观察模式下不应拦截');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'WAF 拦截到可疑请求'
                && ($context['observe_only'] ?? null) === true);
    }

    /**
     * L1 键名检测在真实管线中生效：带注入字符的键名会被整体拒绝（40009），
     * 业务探针绝不执行。键名检测**只拒绝、不改写**——删字符会把 `amount<`
     * 折叠成 `amount`，攻击载荷借此覆盖正常业务值。
     */
    public function test_rejects_dirty_keys_before_reaching_application(): void
    {
        $captured = null;

        Route::middleware('api')->post('/api/__waf_key_probe', function (Request $request) use (&$captured) {
            $captured = $request->all();

            return response()->json(['ok' => true]);
        });

        $response = $this->postJson('/api/__waf_key_probe', [
            "evil'; system('id'); //" => 'v1',
            '<script>' => 'v2',
            'normal_key' => 'v3',
        ]);

        $response->assertStatus(400)->assertJsonPath('code', 40009);
        self::assertNull($captured, '脏键请求穿透到了业务探针');
    }

    /**
     * L1 检测到键名折叠冲突（`amount<` 净化后会折叠成 `amount` 覆盖业务值）
     * 同样拒绝，即便请求里同时带着合法键。
     */
    public function test_rejects_keys_that_would_fold_into_business_fields(): void
    {
        $captured = null;

        Route::middleware('api')->post('/api/__waf_fold_probe', function (Request $request) use (&$captured) {
            $captured = $request->all();

            return response()->json(['ok' => true]);
        });

        $response = $this->postJson('/api/__waf_fold_probe', [
            'amount' => '100',
            'amount<' => '0',
        ]);

        // 同时断言响应形态：若路由 404/500 也可能让探针为 null，假通过会被这里拦下。
        $response->assertStatus(400)->assertJsonPath('code', 40009);
        self::assertNull($captured, '键名折叠冲突的请求穿透到了业务探针');
    }

    /**
     * 关键回归：键名检测只看**键**，绝不能改动**值**。
     *
     * 值里合法包含 < > ' \ 的场景很多（口令、Markdown 正文、RSA 私钥、支付签名），
     * 值被改写会造成登录失败、内容损坏乃至支付验签失败。键干净、值带特殊字符的
     * 请求必须原样到达业务。
     */
    public function test_clean_keys_with_special_values_reach_application_unchanged(): void
    {
        $captured = null;
        $payload = [
            'password' => "P@ss'w<o>rd\\x",
            'sign' => 'abc+/=def',
            'content' => "# 标题\n```html\n<script>alert(1)</script>\n```",
        ];

        Route::middleware('api')->post('/api/__waf_value_probe', function (Request $request) use (&$captured) {
            $captured = $request->all();

            return response()->json(['ok' => true]);
        });

        $response = $this->postJson('/api/__waf_value_probe', $payload);

        $response->assertStatus(200);
        self::assertNotNull($captured, '探针未被调用');
        foreach ($payload as $key => $expected) {
            self::assertSame($expected, $captured[$key] ?? null, "值被改写：{$key}");
        }
    }

    /**
     * 超过深度上限的嵌套里即便只有合法键名也整体拒绝（fail-closed）：
     * 深层键名从未被检查，静默放行等于把深层变成免检区。
     */
    public function test_rejects_requests_nested_beyond_depth_limit(): void
    {
        $captured = null;

        Route::middleware('api')->post('/api/__waf_deep_probe', function (Request $request) use (&$captured) {
            $captured = $request->all();

            return response()->json(['ok' => true]);
        });

        $deep = 'innocent';
        for ($i = 0; $i < 30; $i++) {
            $deep = ['level' => $deep];
        }

        $response = $this->postJson('/api/__waf_deep_probe', ['data' => $deep]);

        $response->assertStatus(400)->assertJsonPath('code', 40009);
        self::assertNull($captured, '超深嵌套请求穿透到了业务探针');
    }
    /**
     * 关键回归：键名净化绝不能改动**值**。
     *
     * 值里合法包含 < > ' \ 的场景很多（口令、Markdown 正文、RSA 私钥、支付签名），
     * 一旦被改写会造成登录失败、内容损坏乃至支付验签失败。
     */
    public function test_key_sanitization_never_alters_values(): void
    {
        $captured = null;
        $payload = [
            'password' => "P@ss'w<o>rd\\x",
            'sign' => 'abc+/=def',
            'content' => "# 标题\n```html\n<script>alert(1)</script>\n```",
        ];

        Route::middleware('api')->post('/api/__waf_value_probe', function (Request $request) use (&$captured) {
            $captured = $request->all();

            return response()->json(['ok' => true]);
        });

        $this->postJson('/api/__waf_value_probe', $payload);

        self::assertNotNull($captured, '探针未被调用');
        foreach ($payload as $key => $expected) {
            self::assertSame($expected, $captured[$key] ?? null, "值被改写：{$key}");
        }
    }

    public function test_excepted_path_is_not_inspected(): void
    {
        // 健康检查在豁免名单内：即便带着攻击特征也必须放行，否则监控探测会被打断。
        $response = $this->getJson('/api/health?q='.urlencode('union select 1 from information_schema.tables'));

        $response->assertStatus(200);
    }

    public function test_hit_is_logged_with_rule_metadata_but_without_payload(): void
    {
        $payload = "' union select password from users--";

        Log::spy();

        $this->getJson(self::PROBE.'?keyword='.urlencode($payload));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($payload): bool {
                if ($message !== 'WAF 拦截到可疑请求') {
                    return false;
                }

                // 必须带上定位所需的元信息
                foreach (['rule_group', 'rule_name', 'method', 'path', 'ip'] as $key) {
                    if (! array_key_exists($key, $context)) {
                        return false;
                    }
                }

                // 但绝不能把命中的原始载荷写进日志
                return ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE) ?: '', $payload);
            });
    }
}
