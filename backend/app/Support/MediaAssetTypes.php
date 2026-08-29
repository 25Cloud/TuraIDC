<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 媒体资源允许的 MIME 类型 —— 上传准入与安全分发的单一真源。
 *
 * 存在的理由：这份名单此前散落在多处，各处形态不同（上传门禁是 MIME 列表、
 * 媒体服务是 mime↔ext 映射、整理器是扩展名列表），彼此没有任何机制保证一致。
 * 实际已经飘了：上传门禁不含 SVG，而分发端 SecureAssetController 用
 * `str_starts_with($mime, 'image/')` 判断 —— `image/svg+xml` 恰好通过。
 *
 * SVG 为什么必须排除：SVG 是 XML 文档，可内嵌 <script> 与事件属性。以
 * `Content-Disposition: inline` 直接返回时，浏览器把它当文档渲染并**在本站源执行脚本**，
 * `X-Content-Type-Options: nosniff` 拦不住（MIME 本来就是 image/svg+xml，不涉及嗅探）。
 *
 * 之前挡住这条路径的唯一原因是「两条上传路径的 MIME 白名单恰好都不含 SVG」——
 * 那是「没有途径放进去」，不是设计防护。磁盘上一旦出现 .svg（数据迁移、手工放置、
 * 将来放宽上传白名单），洞就凭空出现。本类把该前提变成显式约束。
 */
final class MediaAssetTypes
{
    /** @var list<string> */
    public const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @var list<string> */
    public const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-m4v',
    ];

    /**
     * 允许上传的全部 MIME。
     *
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return array_merge(self::IMAGE_MIME_TYPES, self::VIDEO_MIME_TYPES);
    }

    /**
     * 是否为允许以 inline 方式分发的图片类型。
     *
     * 用白名单而不是 `image/` 前缀：前缀会放行 image/svg+xml，
     * 而 SVG 内联渲染等同于在本站源执行任意脚本。
     */
    public static function isAllowedImageMimeType(?string $mimeType): bool
    {
        return in_array(self::normalize($mimeType), self::IMAGE_MIME_TYPES, true);
    }

    /**
     * 去掉 `; charset=...` 之类的参数并小写，便于与白名单精确比对。
     */
    public static function normalize(?string $mimeType): string
    {
        $value = trim((string) $mimeType);
        $separator = strpos($value, ';');
        if ($separator !== false) {
            $value = substr($value, 0, $separator);
        }

        return strtolower(trim($value));
    }
}
