<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UpstreamJwt;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpstreamJwtTest extends TestCase
{
    private const ISSUER = 'https://api.example.test';

    private const AUDIENCE = 'zjmf-upstream';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        // 从类名派生仅供测试使用的 32 字节值，不把可用于生产的密钥写入仓库。
        $this->key = 'base64:'.base64_encode(hash('sha256', self::class, true));
        config([
            'app.key' => $this->key,
            'app.url' => self::ISSUER,
            'services.zjmf_upstream.jwt_issuer' => self::ISSUER,
            'services.zjmf_upstream.jwt_audience' => self::AUDIENCE,
            'services.zjmf_upstream.jwt_ttl' => 7200,
            'services.zjmf_upstream.jwt_clock_skew' => 60,
        ]);
    }

    #[Test]
    public function it_rejects_missing_short_and_example_keys_for_signing_and_verification(): void
    {
        $claims = $this->claims();
        $validToken = UpstreamJwt::encode($claims, $this->key);

        foreach ([
            '',
            'short-key',
            'base64:'.base64_encode('short'),
            'base64:'.base64_encode('SomeRandomString'),
            'base64:'.base64_encode(str_repeat('SomeRandomString', 2)),
            'base64:'.base64_encode(str_repeat("\0", 32)),
        ] as $invalidKey) {
            $this->assertFalse(UpstreamJwt::isUsableKey($invalidKey));

            try {
                UpstreamJwt::encode($claims, $invalidKey);
                $this->fail('无效 APP_KEY 不得签发 JWT');
            } catch (InvalidArgumentException) {
                // 预期拒签
            }

            $this->assertNull(UpstreamJwt::decode($validToken, $invalidKey));
        }
    }

    #[Test]
    public function it_requires_identity_and_protocol_claims(): void
    {
        $required = ['uid', 'is_api', 'iat', 'exp', 'iss', 'aud'];
        foreach ($required as $claim) {
            $claims = $this->claims();
            unset($claims[$claim]);

            $this->assertNull(UpstreamJwt::decode(UpstreamJwt::encode($claims, $this->key), $this->key), $claim);
        }

        foreach ([
            ['is_api' => 0],
            ['is_api' => '1'],
            ['iss' => 'https://other.example.test'],
            ['aud' => 'other-audience'],
            ['uid' => 0],
        ] as $override) {
            $claims = array_merge($this->claims(), $override);
            $this->assertNull(UpstreamJwt::decode(UpstreamJwt::encode($claims, $this->key), $this->key));
        }
    }

    #[Test]
    public function it_enforces_exp_iat_and_clock_window(): void
    {
        $now = time();
        $cases = [
            ['iat' => $now + 61],
            ['exp' => $now - 61],
            ['exp' => $now + 7261],
            ['iat' => $now, 'exp' => $now - 1],
            ['iat' => $now - 7200, 'exp' => $now + 7200],
        ];

        foreach ($cases as $override) {
            $claims = array_merge($this->claims(), $override);
            $this->assertNull(UpstreamJwt::decode(UpstreamJwt::encode($claims, $this->key), $this->key));
        }
    }

    #[Test]
    public function it_accepts_a_valid_token(): void
    {
        $claims = $this->claims();
        $token = UpstreamJwt::encode($claims, $this->key);

        $this->assertSame($claims, UpstreamJwt::decode($token, $this->key));
    }

    #[Test]
    public function a_token_manually_signed_with_an_empty_key_is_rejected(): void
    {
        $header = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode((string) json_encode($this->claims()));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, '', true));

        $this->assertNull(UpstreamJwt::decode($header.'.'.$payload.'.'.$signature, ''));
    }

    #[Test]
    public function it_rejects_a_non_hs256_algorithm_header(): void
    {
        $header = $this->base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = $this->base64UrlEncode((string) json_encode($this->claims()));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->key, true));

        $this->assertNull(UpstreamJwt::decode($header.'.'.$payload.'.'.$signature, $this->key));
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        $now = time();

        return [
            'uid' => 123,
            'is_api' => 1,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 7200,
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
