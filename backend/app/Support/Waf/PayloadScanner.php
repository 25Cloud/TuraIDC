<?php

declare(strict_types=1);

namespace App\Support\Waf;

use Illuminate\Http\Request;

/**
 * 入站请求规则匹配引擎。
 *
 * 把请求的各个
 * 部位（查询串 / 路径 / 请求体 / Cookie / UA）拼成待检字符串，逐条过正则规则库，
 * 命中即返回，交由中间件决定拦截或仅记录。
 *
 * 与参照实现的三处关键差异：
 *
 * 1. **绝不二次 urldecode。** 对已解码的输入再 `urldecode` 一次会把用户字面
 *    输入的 `%20`/`%2B`/`%25` 改写，卡密、查单密码等字段会整体失真——同类
 *    入站过滤层踩过的真实事故形态。PHP/Laravel 在解析请求时已完成解码，这里
 *    只做 `http_build_query` 重新拼接用于匹配，不再解码。
 *
 * 2. **超长载荷分块覆盖，绝不截断丢弃。** 截断看似防住了正则回溯 DoS，实则给
 *    攻击者留了"把载荷放在截断点之后"的免检区。这里按块扫描全部字节，块间
 *    重叠若干字节防跨边界漏检；每块长度有限，回溯放大仍被限制在单块内。
 *
 * 3. **fail-closed 兜底。** 无法被规则库完整、正确检查的输入——嵌套超过
 *    max_depth、含无效 UTF-8（会让 `u` 修饰符的 preg_match 整体失败，若视为
 *    未命中即等于规则库全体失效）——直接按 fatal_rules 固定规则命中拒绝，
 *    与"密钥缺省即拒绝"同一原则。限额与规则名全部来自注入的 waf 配置。
 *
 * 本类只读不改。输入净化由 RichHtmlSanitizer / TextSanitizer 在各自的语义位置
 * 负责，职责不混。
 */
class PayloadScanner
{
    private const DEFAULT_SCAN_BLOCK_SIZE = 20000;

    private const DEFAULT_SCAN_BLOCK_OVERLAP = 256;

    private const DEFAULT_MAX_DEPTH = 12;

    /**
     * 规则库与限额由外部注入，本类不读 config()。
     *
     * 注入形态两种皆可：完整的 waf 配置数组（AppServiceProvider），或仅 rules
     * 数组（测试与手工构造，此时限额取默认值）。
     *
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $resolved = $config ?? (function_exists('config') ? (array) config('waf', []) : []);

        if (! array_key_exists('rules', $resolved)) {
            $resolved = ['rules' => $resolved];
        }

        $this->rules = (array) ($resolved['rules'] ?? []);

        $limits = (array) ($resolved['limits'] ?? []);
        $this->scanBlockSize = max(1024, (int) ($limits['scan_block_size'] ?? self::DEFAULT_SCAN_BLOCK_SIZE));
        $this->scanBlockOverlap = max(0, min(
            $this->scanBlockSize - 1,
            (int) ($limits['scan_block_overlap'] ?? self::DEFAULT_SCAN_BLOCK_OVERLAP),
        ));
        $this->maxDepth = max(1, (int) ($limits['max_depth'] ?? self::DEFAULT_MAX_DEPTH));

        // 类别停用：逗号分隔字符串，小写归一；未配置时为空集（全部类别生效）。
        $this->disabledCategories = array_values(array_filter(array_map(
            static fn (string $c): string => strtolower(trim($c)),
            explode(',', (string) ($resolved['disabled_categories'] ?? '')),
        )));

        $fatal = (array) ($resolved['fatal_rules'] ?? []);
        $this->fatalRules = [
            'depth_exceeded' => (string) ($fatal['depth_exceeded'] ?? '嵌套层级超限'),
            'invalid_encoding' => (string) ($fatal['invalid_encoding'] ?? '无效字符编码'),
        ];
    }

    /**
     * 规则库由外部注入，本类不读 config()。
     *
     * 这样做的原因：直接在类内调用 `config()` 会把引擎与框架启动状态绑死，
     * 既无法在不启动应用的情况下单测，也无法在别处（如 CLI 校验工具）复用。
     * 容器绑定见 AppServiceProvider，中间件拿到的实例已注入 config('waf')。
     *
     * @var array<string, list<array{name?: string, pattern?: string, id?: string, category?: string, enable?: bool}>>
     */
    private array $rules;

    private int $scanBlockSize;

    private int $scanBlockOverlap;

    private int $maxDepth;

    /** @var list<string> 被停用的规则类别（小写）；命中类别的规则整体跳过。 */
    private array $disabledCategories = [];

    /** @var array<string, string> */
    private array $fatalRules;

    /** targets() 摊平过程中是否遇到超过深度上限的输入。 */
    private bool $depthExceeded = false;

    /**
     * 检查请求，返回命中的规则；未命中返回 null。
     *
     * @return array{group: string, name: string}|null
     */
    public function inspect(Request $request): ?array
    {
        $this->depthExceeded = false;

        $targets = $this->targets($request);

        // 深度超限发生在摊平时，与具体部位无关，统一在扫描前拦截。
        if ($this->depthExceeded) {
            return ['group' => 'limit', 'name' => $this->fatalRules['depth_exceeded']];
        }

        foreach ($targets as $group => $subject) {
            if ($subject === '' || ! isset($this->rules[$group])) {
                continue;
            }

            // 无效 UTF-8 会让 u 修饰符的 preg_match 整体返回 false——若按未命中
            // 处理，攻击者用一个非法字节就能让整条规则库失效。编码非法即拒绝。
            if (@preg_match('//u', $subject) !== 1) {
                return ['group' => 'limit', 'name' => $this->fatalRules['invalid_encoding']];
            }

            $hit = $this->matchChunks((array) $this->rules[$group], $subject);
            if ($hit !== null) {
                return [
                    'group' => $group,
                    'name' => (string) ($hit['name'] ?? 'unnamed'),
                    'id' => isset($hit['id']) ? (string) $hit['id'] : null,
                    'category' => isset($hit['category']) ? (string) $hit['category'] : null,
                    'severity' => isset($hit['severity']) ? (string) $hit['severity'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * 组装各检测部位的待检字符串。
     *
     * 顺序即检测优先级：路径与查询串成本最低且最常命中，放在前面短路。
     * header 是独立检测维度：攻击载荷可藏在 X-Forwarded-For、Referer 或任意
     * 自定义头里进入日志与下游解析；cookie 与 UA 单列，不重复进 header。
     *
     * @return array<string, string>
     */
    private function targets(Request $request): array
    {
        return [
            'path' => rawurldecode($request->getPathInfo()),
            'query' => $this->flatten($request->query()),
            'body' => $this->flatten($this->bodyInput($request)),
            'cookie' => $this->flatten($request->cookies->all()),
            'header' => $this->flatten($this->headerInput($request)),
            'ua' => (string) $request->userAgent(),
        ];
    }

    /**
     * 取参与匹配的请求头（排除已单列的 cookie 与 UA）。
     *
     * @return array<array-key, mixed>
     */
    private function headerInput(Request $request): array
    {
        return array_diff_key($request->headers->all(), [
            'cookie' => true,
            'user-agent' => true,
        ]);
    }

    /**
     * 取请求体输入。
     *
     * 文件上传不参与匹配：二进制内容命中正则纯属偶然，且会带来巨大的匹配开销。
     *
     * @return array<array-key, mixed>
     */
    private function bodyInput(Request $request): array
    {
        // JSON 请求体也要检测：现代前端大多以 application/json 提交。
        // json()->all() 在报文非法时返回空数组，无需再做类型判断。
        if ($request->isJson()) {
            return $request->json()->all();
        }

        return $request->post();
    }

    /**
     * 把任意层级的输入摊平成一个可匹配的字符串。
     *
     * 用 `key=value` 拼接而非仅取值：部分攻击载荷藏在参数名里（例如
     * `?a[eval(...)]=1`），只看值会漏。
     *
     * @param  array<array-key, mixed>  $input
     */
    private function flatten(array $input): string
    {
        if ($input === []) {
            return '';
        }

        $parts = [];
        $this->walk($input, $parts);

        return implode('&', $parts);
    }

    /**
     * 递归摊平嵌套数组。
     *
     * 超过深度上限时置位 depthExceeded，由 inspect() 拦截整个请求——深层内容
     * 不参与匹配，静默跳过等于把第 N 层变成免检区。
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $parts
     */
    private function walk(array $input, array &$parts, string $prefix = '', int $depth = 0): void
    {
        if ($depth > $this->maxDepth) {
            $this->depthExceeded = true;

            return;
        }

        foreach ($input as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->walk($value, $parts, $name, $depth + 1);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $parts[] = $name.'='.(string) $value;
            }

            // 对象 / 资源等非标量不参与匹配。
        }
    }

    /**
     * 在一组规则内匹配，超长主体分块覆盖全部字节。
     *
     * 单条 `enable => false` 与 `disabled_categories` 命中的规则在此过滤：
     * 停用属于运营变更（配置里必须有据可查），引擎只按配置放行。
     *
     * @param  list<array{name?: string, pattern?: string, id?: string, category?: string, enable?: bool}>  $rules
     */
    private function matchChunks(array $rules, string $subject): ?array
    {
        if (strlen($subject) <= $this->scanBlockSize) {
            return $this->matchGroup($rules, $subject);
        }

        $length = strlen($subject);
        $step = $this->scanBlockSize - $this->scanBlockOverlap;

        for ($offset = 0; $offset < $length; $offset += $step) {
            $chunk = $this->utf8SafeChunk($subject, $offset, $this->scanBlockSize);
            if ($chunk === '') {
                break;
            }

            $hit = $this->matchGroup($rules, $chunk);
            if ($hit !== null) {
                return $hit;
            }

            if ($offset + $this->scanBlockSize >= $length) {
                break;
            }
        }

        return null;
    }

    /**
     * 取一块以完整 UTF-8 字符为边界的子串。
     *
     * 起点落在多字节序列中间时后移（越过它的内容由上一块的重叠区覆盖）；
     * 终点落在序列中间时收短到上一个完整字符。这样每块都是合法 UTF-8，
     * 不会触发 u 修饰符的整体失败。
     */
    private function utf8SafeChunk(string $subject, int $offset, int $length): string
    {
        $length = min($length, strlen($subject) - $offset);
        if ($length <= 0) {
            return '';
        }

        $start = $offset;
        while ($start < strlen($subject) && (ord($subject[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        $end = min($start + $length, strlen($subject));
        while ($end < strlen($subject) && $end > $start && (ord($subject[$end]) & 0xC0) === 0x80) {
            $end--;
        }

        return $start >= $end ? '' : substr($subject, $start, $end - $start);
    }

    /**
     * 在一组规则内匹配，返回命中的规则条目（供命中结果携带 id/category）。
     *
     * @param  list<array{name?: string, pattern?: string, id?: string, category?: string, enable?: bool}>  $rules
     * @return array{name?: string, pattern?: string, id?: string, category?: string}|null
     */
    private function matchGroup(array $rules, string $subject): ?array
    {
        foreach ($rules as $rule) {
            // 单条停用与类别停用在此过滤：停用规则不参与匹配。
            if (($rule['enable'] ?? true) === false) {
                continue;
            }

            if ($this->disabledCategories !== []
                && in_array(strtolower((string) ($rule['category'] ?? '')), $this->disabledCategories, true)) {
                continue;
            }

            $pattern = (string) ($rule['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }

            // 规则以 # 为分隔符、i 忽略大小写、u 支持 UTF-8。
            // 用 @ 抑制畸形正则的告警：一条规则写错不应让整个站点 500，
            // preg_match 返回 false 时视为未命中并继续下一条（编码合法性
            // 已在 inspect() 层校验，这里 false 只可能来自规则本身畸形）。
            if (@preg_match('#'.$pattern.'#iu', $subject) === 1) {
                return $rule;
            }
        }

        return null;
    }
}
