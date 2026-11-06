<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * 收件人地址的规范化与合法性判定。
 *
 * 用途有两处，语义必须一致：
 * 1. 队列任务执行前先判——空/非法收件人直接跳过，**不**制造一条注定失败的投递，
 *    也不占用该任务的重试额度（一个坏地址不该拖累同一批里的其它收件人）；
 * 2. SMTP 传输层最后一道防线——真发信前再判一次，非法就抛永久失败。
 *
 * 顺带拒绝带 CR/LF 的地址：`to` 会进邮件头，含换行就是头注入。
 */
final class MailRecipient
{
    /**
     * 规范化收件人；空、非法、含头注入字符时返回 null。
     *
     * 支持两种常见写法：`a@b.com` 与 `名字 <a@b.com>`（后者只取地址部分）。
     */
    public static function normalize(?string $address): ?string
    {
        $raw = trim((string) $address);

        if ($raw === '' || str_contains($raw, "\r") || str_contains($raw, "\n")) {
            return null;
        }

        $candidate = $raw;
        if (preg_match('/^[^<>\r\n]*<([^<>\r\n]+)>$/', $raw, $matches) === 1) {
            $candidate = trim($matches[1]);
        }

        if ($candidate === '' || str_contains($candidate, ' ') || str_contains($candidate, ',')) {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) === false ? null : $candidate;
    }

    public static function isValid(?string $address): bool
    {
        return self::normalize($address) !== null;
    }
}
