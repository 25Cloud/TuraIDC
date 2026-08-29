<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 通知模板正文的形态判定与入库净化。
 *
 * 存在的理由是「同一件事只能有一个判断」。模板正文在两个时刻被处理：
 * 管理员保存时（NotificationTemplateService）与渲染成邮件时（NotificationService）。
 * 两侧都要回答同一个问题——这段内容当 HTML 还是当纯文本？一旦各写一份判断，就会出现
 * 「存的时候按纯文本放行、渲染的时候按 HTML 原样输出」的缝隙，而这条缝隙正好是
 * 存储型 XSS 的入口。本类把该判断收敛成唯一实现，两侧都从这里取。
 *
 * 净化口径按「渲染时会怎么用」决定，而不是一刀切：
 *
 * - 纯文本正文**不做净化**。它在渲染时走 NotificationService::convertPlainTextToHtml()，
 *   逐行 htmlspecialchars 后再拼 <br>，本身已经安全；此处若强行过一遍 HTML 净化器，
 *   正文里的 `&` 会先变成 `&amp;`，渲染时再被转义成 `&amp;amp;`，等于凭空制造双重编码。
 * - HTML 正文过 RichHtmlSanitizer 白名单净化。这条分支渲染时是把模板**原样**写进邮件
 *   HTML 的（只有替换进去的参数值被转义），模板本体同样是不可信输入。
 * - 标题一律降为纯文本。它最终进邮件头，HTML 在那里没有任何正当用途；
 *   TextSanitizer::clean() 顺带折叠空白与换行，也就堵掉了换行导致的邮件头注入。
 */
final class NotificationTemplateContent
{
    /**
     * 这段模板是否按 HTML 渲染。
     *
     * 判据与 NotificationService 渲染时完全一致——因为渲染侧就是调用本方法。
     */
    public static function looksLikeHtml(string $template): bool
    {
        $normalized = ltrim(trim($template));

        return preg_match('/^(<!doctype\s+html|<html\b|<body\b)/iu', $normalized) === 1
            || preg_match('/<([a-z][a-z0-9]*)(\s|>)/iu', $normalized) === 1;
    }

    /**
     * 模板字段入库前的净化。
     *
     * @param  string  $channel  模板渠道：email / sms
     * @param  string  $field  字段名，仅 subject 与 content 需要净化
     */
    public static function sanitizeForStorage(string $channel, string $field, string $value): string
    {
        if ($field === 'subject') {
            return TextSanitizer::clean($value);
        }

        if ($field !== 'content') {
            return $value;
        }

        // 短信正文不经 HTML 渲染，过 HTML 净化器只会改动内容而不带来任何安全收益。
        if ($channel !== 'email') {
            return $value;
        }

        return self::looksLikeHtml($value)
            ? RichHtmlSanitizer::sanitize($value)
            : $value;
    }
}
