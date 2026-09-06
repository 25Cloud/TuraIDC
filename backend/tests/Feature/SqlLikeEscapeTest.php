<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SqlLike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SqlLike 转义契约在真实数据库上的回归锚点。
 *
 * SqlLike::contains() 产生 `\%`、`\_`、`\\` 转义序列，依赖 MySQL 的默认行为：
 * LIKE 的默认转义字符是 `\`（未启用 NO_BACKSLASH_ESCAPES）。用真实 MySQL 跑
 * 断言，把这一契约钉死——一旦有人改了 sql mode 或转义语义被破坏，本测试会
 * 当场红掉，而不是静默退化成通配符注入。
 *
 * 刻意使用 settings 表（结构最简单、RefreshDatabase 迁移链必然覆盖），通过
 * 查询构造器直接构造 LIKE 条件，与各搜索服务的生产写法同构。造数的键前缀
 * `nlrow-` 刻意不含 `_`/`%`/`\`：通配符关键词是否被字面化，靠"哪几行命中"
 * 就能精确区分，不能让前缀自身混入待匹配字符。
 */
final class SqlLikeEscapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->insert([
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-100%off', 'item_value' => 'x'],
            // 哨兵行：若转义退化（\% 变通配 %），搜 '100%' 会多命中它，断言当场红。
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-100Xoff', 'item_value' => 'x'],
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-a_b', 'item_value' => 'x'],
            // 哨兵行：若 \_ 变通配 _，搜 'a_b' 会多命中它。
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-axb', 'item_value' => 'x'],
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-back\\slash', 'item_value' => 'x'],
            ['group_key' => 'sql_like_test', 'item_key' => 'nlrow-plain', 'item_value' => 'x'],
        ]);
    }

    private function matchNames(string $keyword): array
    {
        return DB::table('settings')
            ->where('item_key', 'like', SqlLike::contains($keyword))
            ->orderBy('item_key')
            ->pluck('item_key')
            ->all();
    }

    public function test_percent_is_matched_literally_not_as_wildcard(): void
    {
        // 转义生效：只命中字面含 "100%" 的行。若转义退化（\% 当通配），模式会
        // 变成「含 100 后跟任意」，哨兵行 nlrow-100Xoff 会被误命中，本断言红。
        self::assertSame(['nlrow-100%off'], $this->matchNames('100%'));
    }

    public function test_underscore_is_matched_literally_not_as_wildcard(): void
    {
        // 转义生效：只命中字面含 "a_b" 的行。若 \_ 退化为通配，哨兵行
        // nlrow-axb（a 任意字符 b）会被误命中，本断言红。
        self::assertSame(['nlrow-a_b'], $this->matchNames('a_b'));
    }

    public function test_backslash_is_matched_literally(): void
    {
        // \ 本身是转义字符，必须先转成 \\ 才能按字面匹配。
        self::assertSame(['nlrow-back\\slash'], $this->matchNames('back\\slash'));
    }

    public function test_plain_keyword_still_matches(): void
    {
        self::assertSame(['nlrow-plain'], $this->matchNames('plain'));
    }

    public function test_wildcard_only_keyword_is_treated_literally(): void
    {
        // 全通配符关键词被转义成字面串：搜索 "%" 只命中字面含 % 的行（若通配
        // 生效则 6 行全中），搜索 "_" 同理——不再全表扫描、不再跨行枚举。
        self::assertSame(['nlrow-100%off'], $this->matchNames('%'));
        self::assertSame(['nlrow-a_b'], $this->matchNames('_'));
    }
}
