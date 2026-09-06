<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Waf\KeySanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 请求键名检测器行为约束。
 *
 * 对应 acg-faka 3.6.3 修复的 P0（WAF 只清值不清键，键名成为 RCE/XSS 载体）。
 * 本类只**检测**不**改写**——删字符式净化会把 `amount<` 折叠成 `amount`，让
 * 攻击载荷覆盖正常业务值；脏键由中间件整体拒绝。因此本测试的两条主线：
 *   1. 危险字符必须被识别为脏；
 *   2. **合法数据必须恒为阴性**——这条比第 1 条更重要：键名检测是全局生效的，
 *      一旦误伤正常请求，影响面是整站。
 */
final class WafKeySanitizerTest extends TestCase
{
    private KeySanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new KeySanitizer;
    }

    // ---------- 危险键名必须被识别 ----------

    /**
     * @param  array<string, mixed>  $dirty
     */
    #[DataProvider('dirtyKeyProvider')]
    public function test_flags_keys_containing_forbidden_characters(array $dirty): void
    {
        self::assertTrue($this->sanitizer->hasDirtyKey($dirty), '脏键未被识别');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function dirtyKeyProvider(): iterable
    {
        yield 'PHP 字符串转义与命令注入' => [["key'; system('id'); //" => 'v']];
        yield 'HTML 标签' => [['<script>alert(1)</script>' => 'v']];
        yield '反斜杠' => [['a\\\\b' => 'v']];
        yield '控制字符与换行' => [["evil\nINJECTED=1\x00" => 'v']];
        yield '嵌套数组中的脏键' => [['outer' => ['in<ner' => ['deep"est' => 'v']]]];
        yield '与合法键折叠冲突的组合' => [['amount' => '100', 'amount<' => '0']];
        yield 'JSON 体里的深层脏键' => [['a' => ['b' => ['c' => ['d<>' => 1]]]]];
    }

    // ---------- 合法数据必须恒为阴性（最关键） ----------

    /**
     * @param  array<array-key, mixed>  $input
     */
    #[DataProvider('legitimateInputProvider')]
    public function test_is_negative_for_legitimate_input(array $input): void
    {
        self::assertFalse(
            $this->sanitizer->hasDirtyKey($input),
            '合法输入被误判为脏：'.json_encode($input, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function legitimateInputProvider(): iterable
    {
        yield '普通参数' => [['page' => 1, 'per_page' => 20, 'keyword' => '云服务器']];
        yield '配置键' => [['site_name' => 'X', 'smtp_port' => 465, 'alipay_private_key' => 'MIIE...']];
        yield '点号分隔键' => [['home_hero.slides' => '[]', 'a.b.c' => 1]];
        yield '中划线下划线' => [['order-no' => 'X', 'user_id' => 2]];
        yield '中文键' => [['规格' => '4C8G', '机房' => '香港']];
        yield '整数索引列表' => [[0 => 'a', 1 => 'b', 2 => 'c']];
        yield '嵌套结构' => [['filter' => ['status' => ['active', 'pending']]]];
        // 支付回调的典型参数名——键名检测对它们必须是阴性，否则验签请求会被拒
        yield '支付宝回调参数名' => [[
            'out_trade_no' => 'TU202608280001',
            'trade_no' => '2026082822001',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '99.00',
            'sign_type' => 'RSA2',
            'sign' => 'abc+/=def',
        ]];
    }

    public function test_integer_keys_are_never_dirty(): void
    {
        // 整数键是数组索引不是输入字段名，恒为阴性。
        self::assertFalse($this->sanitizer->hasDirtyKey([0 => 'a', 1 => 'b']));
    }

    public function test_empty_array_is_clean(): void
    {
        self::assertFalse($this->sanitizer->hasDirtyKey([]));
    }

    // ---------- 深度超限（fail-closed） ----------

    public function test_depth_beyond_limit_is_treated_as_dirty(): void
    {
        // 超过 MAX_DEPTH 的嵌套中键名从未被检查——无法证明干净的输入按脏处理，
        // 否则深层就是免检区。
        $deep = 'x';
        for ($i = 0; $i < 40; $i++) {
            $deep = ['k' => $deep];
        }

        self::assertTrue($this->sanitizer->hasDirtyKey($deep), '超深嵌套被当作干净放行');
        // 只要不栈溢出即通过（断言在上一行）。
    }
}
