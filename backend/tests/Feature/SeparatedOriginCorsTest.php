<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeparatedOriginCorsTest extends TestCase
{
    public function test_default_cors_policy_allows_only_the_three_frontend_origins(): void
    {
        $this->assertSame([
            'http://www.example.test',
            'http://console.example.test',
            'http://admin.example.test',
        ], config('cors.allowed_origins'));
        $this->assertTrue((bool) config('cors.supports_credentials'));
    }

    #[DataProvider('allowedOrigins')]
    public function test_all_configured_frontend_origins_receive_credentials_cors_headers(string $origin): void
    {
        config([
            'cors.allowed_origins' => [
                'http://127.0.0.1:5175',
                'http://127.0.0.1:5173',
                'http://127.0.0.1:5174',
                'https://www.example.test',
                'https://console.example.test',
                'https://admin.example.test',
            ],
        ]);

        $response = $this->withHeaders(['Origin' => $origin])
            ->getJson('/api/v2/site/config');

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertStringContainsString(
            'Content-Disposition',
            (string) $response->headers->get('Access-Control-Expose-Headers')
        );
        $this->assertStringContainsString(
            'Retry-After',
            (string) $response->headers->get('Access-Control-Expose-Headers')
        );
        $this->assertStringContainsString(
            'X-Request-Id',
            (string) $response->headers->get('Access-Control-Expose-Headers')
        );

        if ($origin === 'http://127.0.0.1:5175') {
            $unconfigured = $this->withHeaders(['Origin' => 'http://localhost:5175'])
                ->getJson('/api/v2/site/config');

            $unconfigured->assertOk();
            $this->assertNull($unconfigured->headers->get('Access-Control-Allow-Origin'));
        }
    }

    public function test_patch_preflight_is_accepted_for_a_configured_origin(): void
    {
        config(['cors.allowed_origins' => ['http://127.0.0.1:5174']]);

        $response = $this->call('OPTIONS', '/api/v2/admin/users/1', [], [], [], [
            'HTTP_ORIGIN' => 'http://127.0.0.1:5174',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,x-request-id',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5174');

        $this->assertStringContainsString(
            'PATCH',
            (string) $response->headers->get('Access-Control-Allow-Methods')
        );
    }

    public function test_preflight_result_is_cacheable_to_avoid_a_round_trip_per_request(): void
    {
        // 三端与 API 分域，浏览器对每个跨源请求都会先发预检。max_age=0 会让预检结果
        // 一次都不能复用——线上实测因此有 34.5% 的 API 请求是纯 OPTIONS。这里锁住"可缓存"。
        config(['cors.allowed_origins' => ['http://127.0.0.1:5174']]);

        $response = $this->call('OPTIONS', '/api/v2/admin/users/1', [], [], [], [
            'HTTP_ORIGIN' => 'http://127.0.0.1:5174',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();

        $this->assertGreaterThan(
            0,
            (int) config('cors.max_age'),
            '预检结果必须可缓存，否则每个跨源请求都要多付一趟 OPTIONS 往返'
        );
        $this->assertSame(
            (int) config('cors.max_age'),
            (int) $response->headers->get('Access-Control-Max-Age')
        );
    }

    public function test_preflight_max_age_zero_is_sent_as_an_explicit_zero_rather_than_omitted(): void
    {
        // 反向覆盖 max_age=0。注意 fruitcake/php-cors 的 configureMaxAge() 判的是 `!== null`，
        // 0 是 int 不是 null，所以该头**照常下发、值为 "0"**（浏览器据此每次都重发预检），
        // 并不是被省略。两者语义不同：省略会让浏览器退回自己的默认预检缓存，显式 0 才是"禁止缓存"。
        // 把这个区别钉住，将来升级依赖若改成「0 即省略」能被立刻发现。
        config([
            'cors.allowed_origins' => ['http://127.0.0.1:5174'],
            'cors.max_age' => 0,
        ]);

        $response = $this->call('OPTIONS', '/api/v2/admin/users/1', [], [], [], [
            'HTTP_ORIGIN' => 'http://127.0.0.1:5174',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();

        $this->assertTrue(
            $response->headers->has('Access-Control-Max-Age'),
            'max_age=0 时该头仍会下发（值为 "0"），不会被省略'
        );
        $this->assertSame('0', $response->headers->get('Access-Control-Max-Age'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedOrigins(): array
    {
        return [
            'local website' => ['http://127.0.0.1:5175'],
            'local console' => ['http://127.0.0.1:5173'],
            'local admin' => ['http://127.0.0.1:5174'],
            'https website' => ['https://www.example.test'],
            'https console' => ['https://console.example.test'],
            'https admin' => ['https://admin.example.test'],
        ];
    }
}
