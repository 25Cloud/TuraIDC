<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * 按请求协商后端 locale。
 *
 * 优先级：
 *   1. X-Locale —— 前端 i18n 当前语言，由请求拦截器主动带上，最准确；
 *   2. Accept-Language —— 浏览器语言偏好，用于前端还没发 X-Locale 的场景；
 *   3. 默认 zh_CN。
 *
 * 影响两件事：ValidationException 的校验消息、BusinessException 的业务消息。
 * 日志不受影响——各调用层记录的是抛异常之前的中文原文，排障不受语言影响。
 */
class SetLocale
{
    /** 内部 locale 代码 -> 该语言常见�� Accept-Language 标签（小写） */
    private const TAGS = [
        'zh_CN' => ['zh-cn', 'zh-hans-cn', 'zh-hans', 'zh-sg', 'zh'],
        'zh_TW' => ['zh-tw', 'zh-hant-tw', 'zh-hant-hk-tw'],
        'zh_HK' => ['zh-hk', 'zh-mo', 'zh-hant-hk', 'zh-hant'],
        'en_US' => ['en-us', 'en'],
    ];

    private const DEFAULT_LOCALE = 'zh_CN';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $explicit = $this->normalize($request->header('X-Locale'));
        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($this->parseAcceptLanguage((string) $request->header('Accept-Language')) as $tag) {
            $matched = $this->normalize($tag);
            if ($matched !== null) {
                return $matched;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * 把一个语言标签映射到内部 locale 代码，识别不了返回 null。
     */
    private function normalize(?string $tag): ?string
    {
        $tag = strtolower(trim((string) $tag));
        if ($tag === '') {
            return null;
        }
        // 前端 i18n 用下划线（zh_HK），HTTP 语言标签用连字符（zh-HK），两种都认
        $tag = str_replace('_', '-', $tag);

        // 去掉 q 值与可能的通配
        $tag = trim(explode(';', $tag)[0]);
        if ($tag === '' || $tag === '*') {
            return null;
        }

        foreach (self::TAGS as $locale => $aliases) {
            if (in_array($tag, $aliases, true)) {
                return $locale;
            }
        }

        // 带子标签的逐级去掉末尾段再试：
        //   zh-hant-hk-mo → zh-hant-hk → zh-hant → zh
        //   en-gb         → en
        // 不能只退到主语言：那样 'zh-hant-hk-mo' 会命中 'zh' 被判成简体，
        // 繁体用户会拿到简体中文的业务消息。前端 shared/locale/index.ts 是同一套逻辑，
        // 改动必须同步，否则界面语言与消息语言会错开。
        $segments = explode('-', $tag);
        while (count($segments) > 1) {
            array_pop($segments);
            $candidate = implode('-', $segments);
            foreach (self::TAGS as $locale => $aliases) {
                if (in_array($candidate, $aliases, true)) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * 解析 Accept-Language，按 q 值从高到低返回标签列表。
     *
     * @return list<string>
     */
    private function parseAcceptLanguage(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $items = [];
        foreach (explode(',', $header) as $index => $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);
            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $segment) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $segment, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }

            $items[] = ['tag' => $tag, 'q' => $quality, 'i' => $index];
        }

        usort($items, static fn (array $a, array $b): int => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);

        return array_column($items, 'tag');
    }
}