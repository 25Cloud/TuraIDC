<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use RuntimeException;

class ZjmfTokenService
{
    /**
     * 签名算法白名单。
     *
     * verify() 恒按本算法重算签名，不读 header.alg 做分派，因此 alg 混淆天然不成立；
     * 这里显式校验是为了挡住「以后有人给 signature() 加算法分派」时退化成 alg=none。
     */
    private const ALGORITHM = 'HS256';

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws RuntimeException 密钥未配置时拒绝签发，避免下发可被任何人伪造的令牌
     */
    public function issue(array $claims, ?int $ttlSeconds = null): string
    {
        $secret = $this->secret();
        if ($secret === '') {
            throw new RuntimeException('ZJMF Bridge 签名密钥未配置，拒绝签发令牌');
        }

        $now = time();
        $payload = array_merge($claims, [
            'iat' => $claims['iat'] ?? $now,
            'nbf' => $claims['nbf'] ?? $now,
            'exp' => $claims['exp'] ?? ($now + ($ttlSeconds ?? (int) config('zjmf_bridge.token_ttl', 7200))),
        ]);

        $header = ['typ' => 'JWT', 'alg' => self::ALGORITHM];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}'),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];

        $segments[] = $this->base64UrlEncode($this->signature($segments[0].'.'.$segments[1], $secret));

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        // 密钥为空时 hash_hmac 会用空串当密钥，任何人都能算出「正确」签名，
        // 从而伪造出任意 uid 的令牌 —— AuthenticateZjmfClient 只按 payload.uid 取用户，
        // 等于任意用户接管。ZjmfSignatureService 早就挡了这一条，令牌链路必须对齐。
        $secret = $this->secret();
        if ($secret === '') {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        if (! is_array($header) || ! hash_equals(self::ALGORITHM, (string) ($header['alg'] ?? ''))) {
            return null;
        }

        $expected = $this->base64UrlEncode($this->signature($encodedHeader.'.'.$encodedPayload, $secret));
        if (! hash_equals($expected, $encodedSignature)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        if (! is_array($payload)) {
            return null;
        }

        $now = time();
        if ((int) ($payload['nbf'] ?? 0) > $now || (int) ($payload['exp'] ?? 0) < $now) {
            return null;
        }

        return $payload;
    }

    /**
     * 与 ZjmfSignatureService::verify() 取同一份密钥、同样 trim，避免两条链路对
     * 「密钥算不算已配置」判断不一致（全空白的密钥在签名侧被拒、在令牌侧却放行）。
     */
    private function secret(): string
    {
        return trim((string) config('zjmf_bridge.secret', ''));
    }

    private function signature(string $input, string $secret): string
    {
        return hash_hmac('sha256', $input, $secret, true);
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string
    {
        return base64_decode(strtr($input, '-_', '+/')) ?: '';
    }
}
