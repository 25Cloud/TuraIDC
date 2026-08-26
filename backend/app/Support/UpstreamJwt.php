<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 上游服务商 API 专用 HS256 JWT（自签自验）。
 *
 * 魔方财务通过 POST {hostname}/zjmf_api_login 换取 JWT，后续请求带
 * Authorization: Bearer <jwt>；本系统只需签发自验，无需与第三方共享密钥，
 * 因此不引入 firebase/php-jwt，直接实现最小 HS256 编解码。
 */
final class UpstreamJwt
{
    private const SIGNING_ALGO = 'sha256';

    public static function encode(array $claims, string $key): string
    {
        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = self::base64UrlEncode((string) json_encode($claims));

        return $header.'.'.$payload.'.'.self::signature($header, $payload, $key);
    }

    /**
     * @return array<string, mixed>|null 验签或有效期不通过时返回 null
     */
    public static function decode(string $token, string $key): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        if (! hash_equals(self::signature($header, $payload, $key), $signature)) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        $now = time();
        if (isset($claims['exp']) && (int) $claims['exp'] < $now) {
            return null;
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now) {
            return null;
        }

        return $claims;
    }

    private static function signature(string $header, string $payload, string $key): string
    {
        return self::base64UrlEncode(hash_hmac(self::SIGNING_ALGO, $header.'.'.$payload, $key, true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? 0 : 4 - (strlen($value) % 4), '=', STR_PAD_RIGHT);

        return (string) base64_decode($padded, true);
    }
}
