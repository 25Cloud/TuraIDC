<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

class ZjmfTokenService
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function issue(array $claims, ?int $ttlSeconds = null): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $claims['iat'] ?? $now,
            'nbf' => $claims['nbf'] ?? $now,
            'exp' => $claims['exp'] ?? ($now + ($ttlSeconds ?? (int) config('zjmf_bridge.token_ttl', 7200))),
        ]);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}'),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];

        $segments[] = $this->base64UrlEncode($this->signature($segments[0].'.'.$segments[1]));

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $expected = $this->base64UrlEncode($this->signature($encodedHeader.'.'.$encodedPayload));
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

    private function signature(string $input): string
    {
        return hash_hmac('sha256', $input, (string) config('zjmf_bridge.secret', ''), true);
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
