<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * 富文本 HTML 白名单消毒（标签 + 属性 + URL 协议三层）。
 *
 * 与 `TextSanitizer` 的分工：`TextSanitizer` 剥掉**全部**标签，产出纯文本（昵称、备注、
 * 摘要用）；本类保留一组安全标签及其安全属性，供需要输出富文本的场景使用。
 *
 * 存在的原因：`strip_tags($html, $allowed)` 的第二个参数只做**标签名**白名单，
 * 属性一律原样留下——`<img src=x onerror=...>` 的标签名 img 在白名单里，于是整条
 * 带着 onerror 通过。SEO 动态渲染正文此前正是这样把管理员写入的内容直接拼进公开页。
 *
 * 口径对齐前端 `shared/htmlSanitizer.js`（同一批内容在前端走那条链路），差异仅一处：
 * 前端会给 <a> 强制补 target="_blank" rel="noopener noreferrer nofollow"、给 <img> 补
 * loading/decoding/referrerpolicy。本类**不**追加这些属性——nofollow 会阻断 SEO 页正文
 * 链接的权重传递，属产品决策，不该由一个消毒器顺手决定。本类只负责移除危险内容。
 */
class RichHtmlSanitizer
{
    /** 允许保留的标签；其余标签剥壳留字（内容保留、标签去掉）。 */
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'del', 'div', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'li',
        'ol', 'p', 'pre', 's', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** 连内容一并删除的标签（留字反而危险或产生噪音）。 */
    private const DROP_TAGS = [
        'base', 'embed', 'form', 'iframe', 'link', 'meta',
        'object', 'script', 'style', 'svg',
    ];

    private const ALLOWED_ATTRS = [
        'alt', 'class', 'colspan', 'decoding', 'height', 'href', 'id',
        'loading', 'rel', 'referrerpolicy', 'rowspan', 'src', 'target',
        'title', 'width',
    ];

    private const URI_ATTRS = ['href', 'src'];

    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** 图片来源不接受 mailto/tel。 */
    private const IMAGE_SCHEMES = ['http', 'https'];

    private const NUMERIC_ATTRS = ['width', 'height', 'colspan', 'rowspan'];

    /** @var array<string, list<string>> 取值枚举受限的属性 */
    private const ENUM_ATTRS = [
        'loading' => ['lazy', 'eager'],
        'decoding' => ['async', 'sync', 'auto'],
        'referrerpolicy' => ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin'],
    ];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        // 外部实体与网络访问一律关闭：正文来自数据库，不该因为一段 HTML 触发出网。
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $dom->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            // 解析失败一律退化为纯文本转义，绝不把原文放行到页面上。
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMNode) {
            return '';
        }

        self::walk($body);

        $output = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= (string) $dom->saveHTML($child);
        }

        return $output;
    }

    private static function walk(DOMNode $node): void
    {
        // 先快照子节点：清理过程中会增删节点，直接遍历活的 NodeList 会漏项。
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::sanitizeElement($child);

                continue;
            }

            // 文本节点保留；注释、CDATA、处理指令一律删除（注释可承载条件注释类载荷）。
            if ($child->nodeType !== XML_TEXT_NODE) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private static function sanitizeElement(DOMElement $element): void
    {
        $tagName = strtolower($element->tagName);

        if (in_array($tagName, self::DROP_TAGS, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
            self::walk($element);
            self::unwrap($element);

            return;
        }

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            self::cleanAttribute($element, $tagName, (string) $attribute->nodeName, (string) $attribute->nodeValue);
        }

        self::walk($element);
    }

    /** 标签不在白名单：去掉标签本身，把子节点提到原位置。 */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private static function cleanAttribute(DOMElement $element, string $tagName, string $rawName, string $value): void
    {
        $name = strtolower($rawName);

        // on* 事件属性是本次修复的核心目标，单独前置判断，不依赖白名单的完备性。
        if (str_starts_with($name, 'on') || ! in_array($name, self::ALLOWED_ATTRS, true)) {
            $element->removeAttribute($rawName);

            return;
        }

        if (in_array($name, self::URI_ATTRS, true) && ! self::isSafeUrl($value, $tagName, $name)) {
            $element->removeAttribute($rawName);

            return;
        }

        if (in_array($name, self::NUMERIC_ATTRS, true) && preg_match('/^\d{1,4}$/', $value) !== 1) {
            $element->removeAttribute($rawName);

            return;
        }

        $allowedValues = self::ENUM_ATTRS[$name] ?? null;
        if ($allowedValues !== null && ! in_array(strtolower(trim($value)), $allowedValues, true)) {
            $element->removeAttribute($rawName);
        }
    }

    private static function isSafeUrl(string $value, string $tagName, string $attrName): bool
    {
        // 先去掉控制字符与空白：`java\0script:` / `java\tscript:` 这类插入是经典绕过手法，
        // 浏览器解析 URL 时会自行忽略它们。
        $url = preg_replace('/[\x00-\x1F\x7F\s]+/u', '', trim($value)) ?? '';
        if ($url === '') {
            return false;
        }

        if (preg_match('/^(javascript|vbscript|file|data):/i', $url) === 1) {
            return false;
        }

        // 页内锚点与相对路径没有协议，直接放行。
        if (str_starts_with($url, '#')
            || str_starts_with($url, '/')
            || str_starts_with($url, './')
            || str_starts_with($url, '../')) {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $matches) !== 1) {
            // 无协议且非上述相对形态（如 example.com/a）：按相对路径处理，放行。
            return true;
        }

        $scheme = strtolower($matches[1]);
        $allowed = ($tagName === 'img' || $attrName === 'src')
            ? self::IMAGE_SCHEMES
            : self::URL_SCHEMES;

        return in_array($scheme, $allowed, true);
    }
}
