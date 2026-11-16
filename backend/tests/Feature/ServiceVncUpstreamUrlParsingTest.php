<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ServiceVncService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 上游 VNC 地址解析：把上游返回的 noVNC 链接还原成 relay 需要的 host/port/path。
 *
 * 上游存在两种 base64 写法，都必须能解：
 * - `?url=` 直接放 base64 原文；
 * - `?url=` 放 base64 之后再 URL 编码一层的值（`%2F`、`%3D`），parse_str 只解一层，
 *   残留编码会让 base64_decode 严格模式返回 false，表现为「VNC 中转参数解析失败」。
 */
class ServiceVncUpstreamUrlParsingTest extends TestCase
{
    /**
     * @dataProvider encodedWssUrlProvider
     *
     * @param  array<string, mixed>  $expected
     */
    public function test_decodes_upstream_novnc_url_to_relay_params(string $upstreamUrl, array $expected): void
    {
        $params = $this->extractVncParams($upstreamUrl);

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $params, '缺少字段：'.$key);
            $this->assertSame($value, $params[$key], '字段不匹配：'.$key);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function encodedWssUrlProvider(): array
    {
        $token = '62d34918ecf95fdebe84b6cb023da27f';
        $wss = 'wss://mf.example.com/cloud_ws11?token='.$token;

        return [
            'base64 原文' => [
                'https://panel.example.com/dcim/novnc?url='.base64_encode($wss).'&password=p',
                [
                    'host' => 'mf.example.com',
                    'port' => 443,
                    'encrypt' => '1',
                    'path' => 'cloud_ws11?token='.$token,
                    'origin' => 'https://mf.example.com',
                ],
            ],
            'base64 后再 URL 编码（真实上游枣庄铂金格式）' => [
                'https://panel.example.com/dcim/novnc?url='.urlencode(base64_encode($wss)).'&password=p',
                [
                    'host' => 'mf.example.com',
                    'port' => 443,
                    'encrypt' => '1',
                    'origin' => 'https://mf.example.com',
                ],
            ],
            'ws 非加密且带端口' => [
                'https://panel.example.com/dcim/novnc?url='.urlencode(base64_encode('ws://10.1.2.3:6080/ws/vnc?token=abc')),
                [
                    'host' => '10.1.2.3',
                    'port' => 6080,
                    'encrypt' => '0',
                    'path' => 'ws/vnc?token=abc',
                    'origin' => 'http://10.1.2.3:6080',
                ],
            ],
        ];
    }

    public function test_returns_empty_params_for_unparsable_url(): void
    {
        $this->assertSame([], $this->extractVncParams('https://panel.example.com/dcim/novnc?url=not-a-valid-base64'));
        $this->assertSame([], $this->extractVncParams(''));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractVncParams(string $upstreamUrl): array
    {
        $service = (new ReflectionClass(ServiceVncService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceVncService::class, 'extractVncParams');
        $method->setAccessible(true);

        /** @var array<string, mixed> $params */
        $params = $method->invoke($service, $upstreamUrl);

        return $params;
    }
}
