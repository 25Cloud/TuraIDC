<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Waf\Firewall;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 入站 WAF 规则引擎的行为约束。
 *
 * 本测试不启动 Laravel 应用（纯函数级），规则库通过 config() 的容器兜底读取，
 * 因此在 setUp 中直接注入一份与 config/waf.php 同构的规则，避免依赖框架启动。
 */
final class WafFirewallTest extends TestCase
{
    private Firewall $firewall;

    protected function setUp(): void
    {
        parent::setUp();

        // 直接加载 config/waf.php 取真实规则库与限额：测的必须是真正上线的那份
        // 配置，而不是测试里另抄一份——抄的那份迟早与线上漂移，测过了也不代表线上安全。
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2).'/config/waf.php';

        $this->firewall = new Firewall($config);
    }

    /**
     * 构造一个请求对象。
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $cookies
     */
    private function request(
        string $uri = '/api/v2/client/services',
        array $query = [],
        array $body = [],
        array $cookies = [],
        string $ua = 'Mozilla/5.0',
        string $method = 'GET',
    ): Request {
        $request = Request::create($uri, $method, $method === 'GET' ? $query : $body, $cookies);
        $request->headers->set('User-Agent', $ua);

        if ($method === 'GET' && $query !== []) {
            $request->query->replace($query);
        }

        return $request;
    }

    // ---------- 攻击载荷应被拦截 ----------

    public function test_detects_sql_union_injection_in_query(): void
    {
        $hit = $this->firewall->inspect(
            $this->request(query: ['keyword' => "1' union select password from users--"])
        );

        self::assertNotNull($hit);
        self::assertSame('query', $hit['group']);
    }

    public function test_detects_information_schema_probe(): void
    {
        $hit = $this->firewall->inspect(
            $this->request(query: ['id' => '1 and 1=2 union select 1 from information_schema.tables'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_code_execution_payload_in_body(): void
    {
        $hit = $this->firewall->inspect(
            $this->request(body: ['note' => 'eval($_POST[cmd])'], method: 'POST')
        );

        self::assertNotNull($hit);
        self::assertSame('body', $hit['group']);
    }

    public function test_detects_directory_traversal_in_query(): void
    {
        $hit = $this->firewall->inspect(
            $this->request(query: ['file' => '../../../../etc/passwd'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_env_file_probe_in_path(): void
    {
        $hit = $this->firewall->inspect($this->request(uri: '/.env'));

        self::assertNotNull($hit);
        self::assertSame('path', $hit['group']);
    }

    public function test_detects_git_directory_probe_in_path(): void
    {
        $hit = $this->firewall->inspect($this->request(uri: '/.git/config'));

        self::assertNotNull($hit);
        self::assertSame('path', $hit['group']);
    }

    public function test_detects_scanner_user_agent(): void
    {
        $hit = $this->firewall->inspect($this->request(ua: 'sqlmap/1.7.2#stable'));

        self::assertNotNull($hit);
        self::assertSame('ua', $hit['group']);
    }

    public function test_detects_payload_hidden_in_parameter_name(): void
    {
        // 载荷藏在参数名里：只看值会漏，flatten 必须拼上 key。
        $hit = $this->firewall->inspect(
            $this->request(query: ['union select 1 from information_schema.tables' => '1'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_payload_in_nested_array(): void
    {
        $hit = $this->firewall->inspect(
            $this->request(body: ['filter' => ['nested' => ['q' => 'union select 1']]], method: 'POST')
        );

        self::assertNotNull($hit);
    }

    // ---------- 正常业务不得误伤 ----------

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('legitimateQueryProvider')]
    public function test_does_not_flag_legitimate_traffic(array $query): void
    {
        $hit = $this->firewall->inspect($this->request(query: $query));

        self::assertNull($hit, '正常业务参数被误伤：'.json_encode($query, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function legitimateQueryProvider(): iterable
    {
        yield '中文关键词' => [['keyword' => '云服务器 香港 大带宽']];
        yield '分页参数' => [['page' => '2', 'per_page' => '20']];
        yield '订单号' => [['order_no' => 'TU20260828093012345']];
        yield '邮箱' => [['email' => 'user@example.com']];
        yield '域名' => [['domain' => 'my-site.example.com']];
        yield '价格区间' => [['min' => '9.90', 'max' => '1999.00']];
        yield '排序字段' => [['sort' => 'created_at', 'order' => 'desc']];
        yield '状态筛选' => [['status' => 'active']];
        yield 'IP 地址' => [['ip' => '203.0.113.42']];
        yield '含连字符的用户名' => [['username' => 'zhang-san_2026']];
        yield 'URL 参数' => [['redirect' => 'https://console.example.com/dashboard']];
        yield '时间范围' => [['start' => '2026-08-01 00:00:00', 'end' => '2026-08-28 23:59:59']];
    }

    public function test_does_not_flag_normal_browser_user_agent(): void
    {
        $hit = $this->firewall->inspect($this->request(
            ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
        ));

        self::assertNull($hit);
    }

    public function test_does_not_flag_search_engine_crawler(): void
    {
        $hit = $this->firewall->inspect($this->request(
            ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ));

        self::assertNull($hit);
    }

    /**
     * 关键回归：本引擎绝不对已解码的输入再做一次 urldecode。
     *
     * acg-faka 早期版本正是在这里栽了跟头（其仓库 issue #833）：二次解码会把用户
     * 字面输入的 %20/%2B/%25 改写，导致卡密、查单密码一类字段整体失真。本项目从
     * 一开始就不解码，此用例把该约束固化下来——若将来有人"顺手加个 urldecode"，
     * 这条会立刻失败。
     */
    public function test_does_not_double_decode_percent_sequences(): void
    {
        // %2527 二次解码后会变成 %27（单引号），进而可能被 SQL 规则命中。
        // 正确行为：只按字面值匹配，不解码，因此不命中。
        $hit = $this->firewall->inspect(
            $this->request(query: ['code' => 'CARD%2527KEY%250A%252B'])
        );

        self::assertNull($hit, '出现了二次解码，字面 % 序列被改写');
    }

    public function test_empty_request_is_clean(): void
    {
        self::assertNull($this->firewall->inspect($this->request()));
    }

    // ---------- 健壮性 ----------

    public function test_oversized_input_is_fully_scanned_in_blocks(): void
    {
        // 超长输入必须分块覆盖全部字节：既不因正则回溯卡死，也不给"把载荷放在
        // 截断点之后"留免检区——攻击载荷藏在 20 万字节的尾部，仍要命中。
        $hit = $this->firewall->inspect(
            $this->request(body: ['blob' => str_repeat('A', 200000)."' union select 1--"], method: 'POST')
        );

        self::assertNotNull($hit, '超长载荷尾部的攻击串逃逸了规则匹配');
        self::assertSame('body', $hit['group']);
    }

    public function test_oversized_input_does_not_hang(): void
    {
        $started = microtime(true);
        $this->firewall->inspect(
            $this->request(body: ['blob' => str_repeat('A', 200000)], method: 'POST')
        );
        $elapsed = microtime(true) - $started;

        self::assertLessThan(2.0, $elapsed, '超长输入匹配耗时过久，存在 ReDoS 风险');
    }

    public function test_deeply_nested_input_is_rejected_as_fixed_rule(): void
    {
        // 超过 max_depth 的嵌套无法被摊平检查，必须按固定规则拒绝——静默跳过
        // 深层内容等于把第 N 层变成免检区。且不能栈溢出。
        $deep = "union select 1";
        for ($i = 0; $i < 50; $i++) {
            $deep = ['n' => $deep];
        }

        $hit = $this->firewall->inspect($this->request(body: ['deep' => $deep], method: 'POST'));

        self::assertNotNull($hit, '超深嵌套被静默放行');
        self::assertSame('limit', $hit['group']);
        self::assertSame('嵌套层级超限', $hit['name']);
    }

    public function test_invalid_utf8_is_rejected_even_when_clean_payload_is_absent(): void
    {
        // 无效字节会让 u 修饰符的 preg_match 整体失败；若按未命中处理，攻击者
        // 塞一个 \xFF 就能让整条规则库失效。必须按固定规则拒绝。
        $hit = $this->firewall->inspect(
            $this->request(query: ['padding' => "\xFF", 'keyword' => "plain text"])
        );

        self::assertNotNull($hit, '无效 UTF-8 输入被当作未命中放行');
        self::assertSame('limit', $hit['group']);
        self::assertSame('无效字符编码', $hit['name']);
    }

    public function test_clean_payload_hidden_beyond_depth_limit_still_blocked(): void
    {
        // 与上一条互补：即便深层内容本身干净，超限请求也整体拒绝（fail-closed）。
        $deep = 'innocent';
        for ($i = 0; $i < 30; $i++) {
            $deep = ['n' => $deep];
        }

        $hit = $this->firewall->inspect($this->request(body: ['deep' => $deep], method: 'POST'));

        self::assertNotNull($hit);
        self::assertSame('嵌套层级超限', $hit['name']);
    }
}
