<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * SQL 标识符（表名/列名）白名单校验。
 *
 * 标识符无法走参数绑定——绑定只作用于「值」，凡以字符串插值进入 SQL 文本的
 * 表名/列名必须先过这里的白名单。此前同样的校验散落在各服务内各自实现
 * （DatabaseEngineeringService::assertSafeIdentifier、
 * MofangFinanceProviderKeyMigrationService::quotedIdentifier），本类是它们的公共收口；
 * 新代码一律从这里取用，不要再写第四份。
 */
final class SqlIdentifier
{
    /** MySQL 标识符长度上限（字节）。 */
    private const MAX_LENGTH = 64;

    /**
     * 校验纯标识符（不含点号与反引号），合法时原样返回。
     *
     * 允许的字符集即「合法标识符必然落在其中」的白名单：字母/数字/下划线。
     * 空串、超长与任何其他字符（引号、空格、注释符、括号）一律拒绝。
     *
     * @throws InvalidArgumentException
     */
    public static function assertSafe(string $identifier, string $label = 'SQL 标识符'): string
    {
        if ($identifier === ''
            || strlen($identifier) > self::MAX_LENGTH
            || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException("非法{$label}: {$identifier}");
        }

        return $identifier;
    }

    /**
     * 校验限定名（允许至多一段点号，如 `account_transactions.event_type`），每段单独过纯标识符校验。
     *
     * @throws InvalidArgumentException
     */
    public static function assertSafeQualified(string $identifier, string $label = 'SQL 标识符'): string
    {
        $segments = explode('.', $identifier);

        if (count($segments) > 2) {
            throw new InvalidArgumentException("非法{$label}: {$identifier}");
        }

        foreach ($segments as $segment) {
            self::assertSafe($segment, $label);
        }

        return $identifier;
    }
}
