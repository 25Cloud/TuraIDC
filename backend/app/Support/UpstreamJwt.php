<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * 上游服务商 API 专用 HS256 JWT（自签自验）。
 *
 * 魔方财务通过 POST {hostname}/zjmf_api_login 换取 JWT，后续请求带
 * Authorization: Bearer <jwt>；本系统只需签发自验，无需与第三方共享密钥，
 * 因此不引入 firebase/php-jwt，直接实现最小 HS256 编解码。
 */
final class UpstreamJwt
{
    private const ALGORITHM = 'HS256';

    private const SIGNING_ALGO = 'sha256';

    private const MIN_KEY_BYTES = 32;

    private const DEFAULT_CLOCK_SKEW = 60;

    private const DEFAULT_MAX_TTL = 7200;

    /**
     * APP_KEY 是上游 JWT 的唯一签名密钥。空值、短值或示例占位符都不能参与 HMAC。
     */
    public static function isUsableKey(string $key): bool
    {
        return self::keyMaterial($key) !== null;
    }

    public static function encode(array $claims, string $key): string
    {
        $key = self::keyMaterial($key);
        if ($key === null) {
            throw new InvalidArgumentException('上游 JWT 签名密钥未正确配置');
        }

        $headerJson = json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM], JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($claims, JSON_UNESCAPED_SLASHES);
        if (! is_string($headerJson) || ! is_string($payloadJson)) {
            throw new InvalidArgumentException('上游 JWT 载荷无效');
        }

        $header = self::base64UrlEncode($headerJson);
        $payload = self::base64UrlEncode($payloadJson);

        return $header.'.'.$payload.'.'.self::signature($header, $payload, $key);
    }

    /**
     * @return array<string, mixed>|null 验签或有效期不通过时返回 null
     */
    public static function decode(string $token, string $key): ?array
    {
        $key = self::keyMaterial($key);
        if ($key === null) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $headerClaims = json_decode(self::base64UrlDecode($header), true);
        if (! is_array($headerClaims)) {
            return null;
        }

        // header 完全由请求方控制，先校验类型再比较，避免畸形数组触发类型转换异常。
        if (($headerClaims['typ'] ?? null) !== 'JWT' || ($headerClaims['alg'] ?? null) !== self::ALGORITHM) {
            return null;
        }

        if (! hash_equals(self::signature($header, $payload, $key), $signature)) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        if (! self::hasRequiredClaims($claims)) {
            return null;
        }

        $issuer = self::configuredIssuer();
        $audience = trim((string) config('services.zjmf_upstream.jwt_audience', 'zjmf-upstream'));
        if ($issuer === '' || $audience === ''
            || ! hash_equals($issuer, (string) $claims['iss'])
            || ! hash_equals($audience, (string) $claims['aud'])) {
            return null;
        }

        $now = time();
        $clockSkew = max(0, (int) config('services.zjmf_upstream.jwt_clock_skew', self::DEFAULT_CLOCK_SKEW));
        $maxTtl = min(
            self::DEFAULT_MAX_TTL,
            max(1, (int) config('services.zjmf_upstream.jwt_ttl', self::DEFAULT_MAX_TTL)),
        );
        $iat = $claims['iat'];
        $exp = $claims['exp'];

        // 允许有限的两端时钟偏差，但不允许未来签发、过期令牌或超长生命周期。
        if ($iat > $now + $clockSkew
            || $exp < $now - $clockSkew
            || $exp <= $iat
            || ($exp - $iat) > $maxTtl + $clockSkew
            || $exp > $now + $maxTtl + $clockSkew) {
            return null;
        }

        if (array_key_exists('nbf', $claims)
            && (! is_int($claims['nbf']) || $claims['nbf'] > $now + $clockSkew)) {
            return null;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function hasRequiredClaims(array $claims): bool
    {
        return is_int($claims['uid'] ?? null)
            && $claims['uid'] > 0
            && is_int($claims['is_api'] ?? null)
            && $claims['is_api'] === 1
            && is_int($claims['iat'] ?? null)
            && is_int($claims['exp'] ?? null)
            && is_string($claims['iss'] ?? null)
            && is_string($claims['aud'] ?? null);
    }

    private static function configuredIssuer(): string
    {
        $issuer = trim((string) config('services.zjmf_upstream.jwt_issuer', ''));

        return $issuer !== '' ? $issuer : trim((string) config('app.url', ''));
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

    private static function keyMaterial(string $key): ?string
    {
        $key = trim($key);
        if ($key === '' || self::isExampleKey($key)) {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || strlen($decoded) < self::MIN_KEY_BYTES || $decoded === str_repeat("\0", strlen($decoded))) {
                return null;
            }
        } elseif (strlen($key) < self::MIN_KEY_BYTES) {
            return null;
        }

        // 保留完整 APP_KEY 作为 HMAC 输入，兼容现有已签发令牌；base64 前缀只用于长度校验。
        return $key;
    }

    private static function isExampleKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        $placeholders = [
            'somerandomstring',
            'some_random_string',
            'your-app-key',
            'your_app_key',
            'change-me',
            'changeme',
            'replace-me',
            'replace_me',
            'dummy-key',
            'dummy_key',
            'example-key',
            'example_key',
        ];

        foreach ($placeholders as $placeholder) {
            if ($normalized === $placeholder || str_contains($normalized, $placeholder)) {
                return true;
            }
        }

        return false;
    }
}
