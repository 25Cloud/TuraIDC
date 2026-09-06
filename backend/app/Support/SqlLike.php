<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SQL LIKE 通配符转义。
 *
 * 用户输入拼进 LIKE 模式时，`%`/`_` 是通配符、`\` 是转义字符：不转义则用户
 * 可注入通配符造成全表扫描（性能）与跨字段枚举探测（信息泄露）。本类把
 * 「先转义再包边界」收口为单点，此前 31 个文件的 LIKE 搜索各自手拼
 * `%{$keyword}%`，无一转义。
 *
 * 转义契约（调用方必须了解）：本类转义产生 `\%`、`\_`、`\\`，依赖 MySQL 的
 * **默认行为**——LIKE 的默认转义字符是 `\`，查询无需也不能再追加 `ESCAPE`
 * 子句（Eloquent 的 where() 不暴露 ESCAPE；逐点改 whereRaw 会丢失绑定与
 * 可读性）。项目数据库基线（MySQL 5.7.44 / 8.x，见 docs/references/database/
 * mysql-version-compatibility.md）未启用 NO_BACKSLASH_ESCAPES；若将来有人
 * 在 sql mode 里开启它，本类的转义会失效，SqlLikeEscapeTest 会当场红掉。
 * SQLite（仅出现在测试内存库）默认不把 `\` 当转义符，同样由该测试区分约束。
 *
 * @see \Tests\Feature\SqlLikeEscapeTest 转义行为在真实 MySQL 上的回归锚点
 */
final class SqlLike
{
    /**
     * 转义值中的 LIKE 通配符与转义符，返回可直接作为 LIKE 模式主体的字符串。
     *
     * 反斜杠必须最先替换，否则会把刚写入的转义符再转义掉。
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** `%value%`：包含匹配。 */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }

    /** `value%`：前缀匹配。 */
    public static function startsWith(string $value): string
    {
        return self::escape($value).'%';
    }

    /** `%value`：后缀匹配。 */
    public static function endsWith(string $value): string
    {
        return '%'.self::escape($value);
    }
}
