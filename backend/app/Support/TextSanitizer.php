<?php

declare(strict_types=1);

namespace App\Support;

class TextSanitizer
{
    public static function clean(?string $value, bool $preserveNewLines = false): string
    {
        $normalized = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strip_tags($normalized);

        return self::finalize($normalized, $preserveNewLines);
    }

    public static function cleanHtml(?string $value, bool $preserveNewLines = false): string
    {
        $normalized = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>/isu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/<br\s*\/?>/iu', "\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/(p|div|li|tr|ul|ol|h[1-6])>/iu', "\n", $normalized) ?? $normalized;
        $normalized = strip_tags($normalized);

        return self::finalize($normalized, $preserveNewLines);
    }

    public static function nullable(?string $value, bool $preserveNewLines = false): ?string
    {
        $cleaned = self::clean($value, $preserveNewLines);

        return $cleaned !== '' ? $cleaned : null;
    }

    public static function nullableHtml(?string $value, bool $preserveNewLines = false): ?string
    {
        $cleaned = self::cleanHtml($value, $preserveNewLines);

        return $cleaned !== '' ? $cleaned : null;
    }

    private static function finalize(string $normalized, bool $preserveNewLines): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        $pattern = $preserveNewLines
            ? '/[^\P{C}\n\t]+/u'
            : '/[^\P{C}\t]+/u';

        $normalized = preg_replace($pattern, '', $normalized) ?? '';
        $normalized = str_replace("\t", ' ', $normalized);

        if ($preserveNewLines) {
            $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? '';

            $lines = array_map(
                static fn (string $line): string => trim(preg_replace('/ {2,}/', ' ', $line) ?? ''),
                explode("\n", $normalized)
            );

            return trim(implode("\n", $lines));
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return trim($normalized);
    }
}
