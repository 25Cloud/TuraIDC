<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 通知模板字段的入库净化。
 *
 * 单独成类是为了让这条安全决策可被测试与阅读——调用方
 * NotificationTemplateService::sanitizeTemplateField() 是私有方法，直接写在那里就只能
 * 靠反射或整条 HTTP 链路去覆盖。
 *
 * 口径：**只有 subject 需要净化，正文一律原样入库。**
 *
 * ## subject 为什么降为纯文本
 *
 * 它最终进邮件头，HTML 在那里没有任何正当用途；TextSanitizer::clean() 顺带折叠空白与
 * 换行。渲染侧 NotificationService 也会剥一次 CRLF，此处是纵深防御而非唯一控制。
 *
 * ## 正文为什么不净化
 *
 * 原实现是 `preg_replace('/<\/?script\b[^>]*>/iu', '', $value)`，注释却声称会移除
 * script/iframe/object/embed 与事件处理器。它不仅漏——9 个常见载荷放行 8 个
 * （`<img onerror>`、`<svg onload>`、`<iframe>`、`<object>`、`<embed>`、`<body onload>`、
 * `javascript:` 全部原样通过）——更会把
 * `<scr<script>ipt>alert(1)</scr</script>ipt>` 在剥掉内层后**重组出**一个可执行的
 * `<script>`：净化这一步自己制造了危险。这是单遍黑名单剥离的固有缺陷。
 *
 * 但换成 RichHtmlSanitizer 白名单是**用错工具**，实测会把模板毁掉：默认模板
 * （EmailNotificationTemplateDefaults）是带 <style> 的完整 HTML 文档，过一遍之后
 * 7536 字节只剩 1898 —— <!doctype>/<html>/<head>/<body> 被剥壳，<style> 连同整段 CSS
 * 被丢弃（它在 DROP_TAGS 里），cellpadding/cellspacing/align 因不在 ALLOWED_ATTRS 而被
 * 清空，连一个 {{占位符}} 都跟着 <style> 一起没了。管理员保存一次，整套邮件排版就废了。
 * 根因是两类内容不同：RichHtmlSanitizer 是**文章正文**净化器，而邮件模板是**带 CSS 的
 * 完整文档**，不是白名单开小了。
 *
 * 而这个字段也确实不需要 HTML 净化，可达性逐条核过：写入侧是管理员路由；读出后
 * NotificationService::renderTemplateContent() 全仓只有一个调用点，通向
 * buildTemplateEmailHtml() → sendEmail()，即**只进邮件客户端**；模板表只有 email/sms
 * 两个渠道，没有站内信；管理端前端 v-html 命中数为 0，也没有返回 HTML 的预览端点。
 * 不存在浏览器同源渲染面，邮件客户端又不执行脚本。
 *
 * 于是结论是「不动」——注意这不等于退回原状：对这个字段而言，不动比原来那样动
 * 严格更安全，因为原实现会凭空造出可执行标签。
 *
 * 若将来正文出现浏览器渲染面（站内信、后台预览），需要的是一个**邮件专用**净化器：
 * 保留文档结构与 table 排版属性、并额外净化 CSS，而不是把这里改成调用 RichHtmlSanitizer。
 */
final class NotificationTemplateContent
{
    /**
     * @param  string  $field  字段名，当前仅 subject 需要净化
     */
    public static function sanitizeForStorage(string $field, string $value): string
    {
        if ($field === 'subject') {
            return TextSanitizer::clean($value);
        }

        return $value;
    }
}
