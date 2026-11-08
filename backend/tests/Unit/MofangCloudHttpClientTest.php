<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiException;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiMode;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudCredentials;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\DcimsCloudClient;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\LegacyCloudClient;

/**
 * 两代魔方云客户端的传输契约回归。
 *
 * 重点锁死三件最容易抄错的事：端点前缀（/v1 与 /api/v1）、鉴权形态
 * （access-token 头 vs Dcims-X-* 四头）、以及新版本的签名必须与真正发出去的
 * 报文逐字节一致。
 */
class MofangCloudHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'mofang_cloud')
        );

        Cache::flush();
    }

    public function test_dcims_client_signs_the_exact_payload_it_sends(): void
    {
        Http::fake([
            'cloud.test/*' => Http::response(['status' => 200, 'msg' => 'ok', 'data' => []], 200),
        ]);

        $client = new DcimsCloudClient($this->dcimsCredentials());
        $client->request('POST', '/server/88/power_on', ['product_id' => 88, 'id' => '88']);

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            if ($request->url() !== 'https://cloud.test/api/v1/server/88/power_on') {
                return false;
            }

            $appid = (string) $request->header('Dcims-X-Appid')[0];
            $nonce = (string) $request->header('Dcims-X-Nonce')[0];
            $timestamp = (string) $request->header('Dcims-X-Timestamp')[0];
            $sign = (string) $request->header('Dcims-X-Sign')[0];

            if ($appid !== 'appid' || strlen($nonce) !== 16 || ! ctype_xdigit($nonce)) {
                return false;
            }

            if ($timestamp === '' || ! is_numeric($timestamp)) {
                return false;
            }

            // 报文必须是键名升序后的紧凑 JSON，并且就是参与签名的那一份字节。
            $body = (string) $request->body();
            if ($body !== '{"id":"88","product_id":88}') {
                return false;
            }

            return $sign === $this->expectedSign($body, $nonce, $timestamp, 'secret');
        });
    }

    public function test_dcims_client_appends_get_parameters_as_strings(): void
    {
        Http::fake([
            'cloud.test/*' => Http::response(['status' => 200, 'data' => []], 200),
        ]);

        $client = new DcimsCloudClient($this->dcimsCredentials());
        $client->request('GET', '/server/88', ['id' => 88]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://cloud.test/api/v1/server/88?id=88'
            && (string) $request->header('Dcims-X-Appid')[0] === 'appid');
    }

    public function test_dcims_client_detects_business_failure_envelope(): void
    {
        Http::fake([
            'cloud.test/*' => Http::response(['status' => 400, 'msg' => '实例不存在', 'data' => []], 200),
        ]);

        $client = new DcimsCloudClient($this->dcimsCredentials());
        $body = $client->request('GET', '/server/88');

        $this->assertFalse(DcimsCloudClient::isSuccess($body));
        $this->assertSame('实例不存在', DcimsCloudClient::messageOf($body));
    }

    public function test_legacy_client_logs_in_with_form_and_uses_access_token_header(): void
    {
        Http::fake([
            'cloud.test/v1/login*' => Http::response('"token-abc"', 200),
            'cloud.test/v1/clouds/88/on' => Http::response(['status' => 'success'], 200),
        ]);

        $client = new LegacyCloudClient($this->legacyCredentials());
        $client->request('POST', '/clouds/88/on');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/v1/login')
            && str_contains((string) $request->body(), 'username=user')
            && str_contains((string) $request->body(), 'password=pass'));

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://cloud.test/v1/clouds/88/on'
            && $request->method() === 'POST'
            && (string) $request->header('access-token')[0] === 'token-abc');
    }

    public function test_legacy_client_relogins_once_on_401_then_replays(): void
    {
        Http::fake([
            'cloud.test/v1/login*' => Http::sequence()
                ->push('"token-old"', 200)
                ->push('"token-new"', 200),
            'cloud.test/v1/clouds/88/status' => Http::sequence()
                ->push('', 401)
                ->push(['status' => 'on'], 200),
        ]);

        Cache::flush();

        $client = new LegacyCloudClient($this->legacyCredentials());
        $body = $client->request('GET', '/clouds/88/status');

        $this->assertSame('on', $body['status']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://cloud.test/v1/clouds/88/status'
            && (string) $request->header('access-token')[0] === 'token-new');
    }

    public function test_legacy_client_surfaces_error_field_as_exception(): void
    {
        Http::fake([
            'cloud.test/v1/login*' => Http::response('"token-abc"', 200),
            'cloud.test/v1/clouds/88/off' => Http::response(['error' => '云主机正在执行任务'], 200),
        ]);

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('云主机正在执行任务');

        (new LegacyCloudClient($this->legacyCredentials()))->request('POST', '/clouds/88/off');
    }

    public function test_legacy_client_uses_agent_entry_when_configured_as_agent(): void
    {
        Http::fake([
            'cloud.test/index.php*' => Http::response(['data' => ['token' => 'agent-token']], 200),
            'cloud.test/index.php' => Http::response(['data' => ['token' => 'agent-token']], 200),
        ]);

        $credentials = new CloudCredentials(
            supplierId: 1,
            baseUrl: 'https://cloud.test',
            account: 'user',
            secret: 'pass',
            mode: CloudApiMode::Legacy,
            agentAccount: true,
        );

        $token = (new LegacyCloudClient($credentials))->token(forceRefresh: true);

        $this->assertSame('agent-token', $token);
    }

    public function test_mode_mapping_accepts_documented_aliases_only(): void
    {
        $this->assertSame(CloudApiMode::Dcims, CloudApiMode::tryFromConfig('dcims'));
        $this->assertSame(CloudApiMode::Dcims, CloudApiMode::tryFromConfig(' DCIMS '));
        $this->assertSame(CloudApiMode::Legacy, CloudApiMode::tryFromConfig('legacy'));
        $this->assertSame(CloudApiMode::Legacy, CloudApiMode::tryFromConfig('3.7.6'));
        $this->assertNull(CloudApiMode::tryFromConfig('auto'));
        $this->assertNull(CloudApiMode::tryFromConfig(''));
        $this->assertNull(CloudApiMode::tryFromConfig('something-else'));
    }

    private function dcimsCredentials(): CloudCredentials
    {
        return new CloudCredentials(
            supplierId: 1,
            baseUrl: 'https://cloud.test',
            account: 'appid',
            secret: 'secret',
            mode: CloudApiMode::Dcims,
        );
    }

    private function legacyCredentials(): CloudCredentials
    {
        return new CloudCredentials(
            supplierId: 1,
            baseUrl: 'https://cloud.test',
            account: 'user',
            secret: 'pass',
            mode: CloudApiMode::Legacy,
        );
    }

    /**
     * 按魔方云新版本接口的签名规则独立重算一遍，用来验证「签的就是发的」。
     */
    private function expectedSign(string $canonicalJson, string $nonce, string $timestamp, string $secret): string
    {
        $dataHash = hash_hmac('sha256', $canonicalJson, $secret);
        $signData = [
            'secret' => $secret,
            'data' => $dataHash,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
        ];
        ksort($signData);

        return hash_hmac('sha256', http_build_query($signData), $secret);
    }
}
