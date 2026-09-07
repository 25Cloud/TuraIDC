<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponseBuilder;
use App\Support\Waf\PayloadScanner;
use App\Support\Waf\KeySanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 入站 WAF 中间件 —— 所有 API 请求的统一前置过滤入口。
 *
 * 挂载于 api 中间件组最前：TuraIdc 的接口全部收敛在 api 组，统一挂载覆盖面
 * 完整，防护在请求进入业务逻辑之前就生效，也不会因为新增控制器时忘记声明
 * 而漏防——防护补丁散落到各个控制器的路线本仓库不走。
 *
 * 分两层，顺序不可颠倒：
 *
 *  L1 键名检测（只拒绝、不改写）：中和数组键里的注入字符的思路被否决——删字符
 *     会把 `amount<` 与 `amount` 折叠成同一个键，攻击载荷借此覆盖正常业务值。
 *     检测到脏键（或嵌套超深无法证明干净）即整体拒绝，详见 KeySanitizer。
 *     放在检测之前，避免带脏键的请求进入规则匹配消耗资源。
 *  L2 规则检测（只拒绝、不改写）：命中攻击特征即拒绝，返回 40009。
 *     超长、超深、无效编码等无法完整检查的情形由引擎按 fatal_rules 固定规则
 *     拒绝（fail-closed），详见 PayloadScanner。
 *
 * ⚠ 为什么**不**做全局的「值」净化（这是与"入站改写一切"路线最根本的分歧）：
 *
 * "入站改写一切"路线会把 $_POST/$_GET/$_REQUEST/$_SERVER/json 全部改写一遍。
 * 这条路线在 TuraIdc 上会直接造成资金级事故，因为本项目有四类**绝不能被改写**
 * 的入参：
 *
 *  1. **支付回调验签**：VerifyAlipayCallbackSignature 用 `$request->all()` 参与
 *     验签，改写任意一个字节都会导致验签失败——收不到钱。
 *  2. **密码**：口令合法包含 < > & ' 等字符，改写会让注册与登录时的口令不一致。
 *  3. **Markdown 正文**：文章 content 存的是 Markdown 源文，帮助文档里出现
 *     `<script>` 代码块示例是完全正常的，剥标签会毁掉内容。
 *  4. **密钥与证书**：RSA 私钥含换行与特殊字符，净化会损坏内容。
 *
 * 因此「值」的净化保持按语义显式调用（TextSanitizer 取纯文本、RichHtmlSanitizer
 * 走富文本白名单），由各调用点决定口径——这不是"补丁散落"，而是必要的语义区分：
 * 昵称、工单正文、SEO 内容需要的净化强度本就不同，统一改写只会同时做错三件事。
 *
 * 拦截返回 40009 而非 403：403 在本项目语义上是"已认证但无权限"，会与权限体系
 * 的告警混淆；40009 是 4xxxx 段里的独立业务码，便于在日志与监控中单独统计。
 */
class WebApplicationFirewall
{
    public function __construct(
        private readonly PayloadScanner $scanner,
        private readonly KeySanitizer $keySanitizer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('waf.enabled', true)) {
            return $next($request);
        }

        if ($this->isExcepted($request)) {
            return $next($request);
        }

        if ($this->hasDirtyKeys($request)) {
            if (config('waf.log_hits', true)) {
                Log::warning('WAF 拦截到异常请求键名', [
                    'method' => $request->method(),
                    'path' => $request->getPathInfo(),
                    'ip' => $request->ip(),
                ]);
            }

            // 观察模式：只记录不拦截，用于上线初期确认无误伤。
            if (config('waf.observe_only', false)) {
                return $next($request);
            }

            return ApiResponseBuilder::error(40009, '请求包含不安全的内容，已被拦截', null, 400);
        }

        $hit = $this->scanner->inspect($request);

        if ($hit === null) {
            return $next($request);
        }

        if (config('waf.log_hits', true)) {
            $this->logHit($request, $hit);
        }

        // 观察模式：只记录不拦截，用于上线初期确认无误伤。
        if (config('waf.observe_only', false)) {
            return $next($request);
        }

        return ApiResponseBuilder::error(40009, '请求包含不安全的内容，已被拦截', null, 400);
    }

    /**
     * L1：检测请求各输入袋的键名，任何脏键即拒绝整个请求（只拒绝、不改写）。
     *
     * 三处都要检查，漏一处即失效：query（?a=1）、request（表单体）、json（JSON 体）。
     *
     * 合法键名不含被禁止的字符，因此正常请求恒为阴性；命中即说明请求里确实
     * 有异常键名。绝不做「删字符后放行」——删字符会把 `amount<` 折叠成 `amount`，
     * 攻击载荷借此覆盖正常业务值（见 KeySanitizer 类注释）。
     */
    private function hasDirtyKeys(Request $request): bool
    {
        return $this->keySanitizer->hasDirtyKey($request->query->all())
            || $this->keySanitizer->hasDirtyKey($request->request->all())
            || ($request->isJson() && $this->keySanitizer->hasDirtyKey($request->json()->all()));
    }

    /**
     * 豁免路径判断。
     *
     * 富文本正文、工单内容、上游回调等本就可能包含"像攻击载荷"的合法文本，
     * 必须放行，否则必然误伤真实业务。
     *
     * 对键名检测而言这层豁免是**双保险**：回调报文的键名由对端决定且参与验签，
     * 即便键名检测对它们实际上是阴性，也不在这条路径上冒任何拒绝报文的风险。
     */
    private function isExcepted(Request $request): bool
    {
        $patterns = (array) config('waf.except', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    /**
     * 记录命中日志。
     *
     * 只记录规则名与请求元信息，**不记录命中的具体载荷**：载荷可能含用户口令等
     * 敏感内容，且攻击串往往很长，写进日志会造成膨胀与二次泄露。
     */
    private function logHit(Request $request, array $hit): void
    {
        Log::warning('WAF 拦截到可疑请求', [
            'rule_group' => $hit['group'],
            'rule_name' => $hit['name'],
            'rule_id' => $hit['id'] ?? null,
            'rule_category' => $hit['category'] ?? null,
            'rule_severity' => $hit['severity'] ?? null,
            'method' => $request->method(),
            'path' => $request->getPathInfo(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
            'observe_only' => (bool) config('waf.observe_only', false),
        ]);
    }
}
