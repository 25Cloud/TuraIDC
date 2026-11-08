<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiException;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiMode;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudCredentials;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\DcimsCloudClient;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\DcimsCloudOperations;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\LegacyCloudClient;

/**
 * {@see DcimsCloudOperations} 的端到端（HTTP 层被 fake）验证。
 *
 * 重点不在「方法能返回数组」，而在三件会直接导致线上验签失败或静默丢参数的事：
 * 请求方法、路径、报文里的 `id`、以及四个 `Dcims-X-*` 头。
 */
class MofangCloudDcimsOperationsTest extends TestCase
{
    private const BASE_URL = 'https://cloud.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPluginLib();
    }

    public function test_power_on_posts_to_power_on_with_id_and_signature_headers(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/power_on' => Http::response(['status' => 200, 'msg' => '已下发', 'data' => []]),
        ]);

        $result = $this->operations()->power('on', 88);

        $this->assertTrue($result['ok']);
        $this->assertSame('已下发', $result['message']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/api/v1/server/88/power_on'
                && is_array($payload)
                // 契约 §5.6：路径与报文都要带 id，且是字符串
                && ($payload['id'] ?? null) === '88'
                && ($payload['product_id'] ?? null) === 88
                && $request->hasHeader('Dcims-X-Appid', 'appid')
                && $request->hasHeader('Dcims-X-Nonce')
                && $request->hasHeader('Dcims-X-Timestamp')
                && $request->hasHeader('Dcims-X-Sign');
        });
    }

    public function test_host_detail_normalizes_upstream_fields(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88*' => Http::response([
                'status' => 200,
                'msg' => 'ok',
                'data' => [
                    'id' => 88,
                    'primary_ip' => '203.0.113.7',
                    'ips' => "203.0.113.8\r\n203.0.113.9",
                    'username' => 'root',
                    'password' => 'secret-pass',
                    'port' => 2222,
                    'os' => 'Ubuntu 22.04',
                    'os_id' => 12,
                    'limit_traffic' => 500,
                    'free_quota' => 5368709120,
                    'used_traffic' => 1073741824,
                    'status' => 'normal',
                    'wltag' => 'web-01',
                    'cpu' => 4,
                    'memory' => 8192,
                    'disk' => [['id' => 1, 'size' => 40, 'type' => 'system']],
                    'node_id' => 3,
                    'kvmid' => 'kvm-abc',
                    'active_time' => 1700000000,
                    'due_time' => 1731536000,
                    'rescue' => 0,
                ],
            ]),
        ]);

        $detail = $this->operations()->hostDetail(88);

        $this->assertSame(88, $detail['id']);
        $this->assertSame('203.0.113.7', $detail['dedicatedip']);
        $this->assertSame(['203.0.113.8', '203.0.113.9'], $detail['assignedips']);
        $this->assertSame(500, $detail['bwlimit']);
        $this->assertSame(1.0, $detail['bwusage']);
        $this->assertSame(5, $detail['bwlimit_included']);
        $this->assertSame('Active', $detail['domainstatus']);
        $this->assertSame('web-01', $detail['domain']);
        $this->assertSame('Ubuntu 22.04', $detail['os']);
        $this->assertSame(12, $detail['os_id']);
        $this->assertSame(4, $detail['cpu']);
        $this->assertSame(8192, $detail['memory']);
        $this->assertSame('system', $detail['disk'][0]['type']);
        $this->assertSame(3, $detail['node_id']);
        $this->assertSame('kvm-abc', $detail['kvmid']);
        $this->assertSame(date('Y-m-d H:i:s', 1700000000), $detail['created_at']);
        $this->assertSame(date('Y-m-d H:i:s', 1731536000), $detail['expires_at']);
        $this->assertSame(200, $detail['raw']['status']);

        // GET 也要把 id 带进 query（参与签名）
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/v1/server/88?id=88'));
    }

    public function test_hard_power_actions_are_rejected_with_chinese_reason(): void
    {
        Http::fake();

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('强制断电');

        $this->operations()->power('hard_off', 88);
    }

    public function test_security_groups_are_reported_as_unsupported(): void
    {
        Http::fake();

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('安全组');

        $this->operations()->securityGroups(88);
    }

    public function test_reinstall_sends_mirror_and_remote_port(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/reinstall' => Http::response(['status' => 200, 'msg' => '重装任务已下发', 'data' => []]),
        ]);

        $result = $this->operations()->reinstall(88, [
            'os_id' => 12,
            'password' => 'p@ssw0rd',
            'port' => 2222,
        ]);

        $this->assertTrue($result['ok']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/api/v1/server/88/reinstall'
                && is_array($payload)
                && ($payload['id'] ?? null) === '88'
                && ($payload['mirror_os_id'] ?? null) === 12
                && ($payload['remote_port'] ?? null) === 2222
                && ($payload['os_password'] ?? null) === 'p@ssw0rd'
                // 契约 §5.4 的默认值：不传就按 bios / default / full
                && ($payload['boot_mode'] ?? null) === 'bios'
                && ($payload['partition_mode'] ?? null) === 'default'
                && ($payload['format_type'] ?? null) === 'full'
                // 可选字段没给就不该出现在报文里（空值会被上游当成显式清空）
                && ! array_key_exists('partition_template_id', $payload)
                && ! array_key_exists('custom_tag', $payload);
        });
    }

    public function test_legacy_client_is_rejected_at_construction(): void
    {
        $legacy = new LegacyCloudClient(new CloudCredentials(
            supplierId: 1,
            baseUrl: self::BASE_URL,
            account: 'user',
            secret: 'pass',
            mode: CloudApiMode::Legacy,
        ));

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('新版本');

        new DcimsCloudOperations($legacy);
    }

    public function test_features_absent_from_new_api_return_empty_arrays(): void
    {
        Http::fake();

        $operations = $this->operations();

        $this->assertSame([], $operations->statistics(88, 'cpu', []));
        $this->assertSame([], $operations->areas());

        // 两个「返回空数组」的方法都不该发请求
        Http::assertNothingSent();
    }

    public function test_host_status_maps_power_status_to_platform_state(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/power_status*' => Http::response([
                'status' => 200,
                'data' => ['power_status' => 'on'],
            ]),
        ]);

        $status = $this->operations()->hostStatus(88);

        $this->assertSame('on', $status['status']);
        $this->assertSame('', $status['task']);
    }

    public function test_task_status_maps_running_task_with_progress_and_step(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/tasks*' => Http::response([
                'status' => 200,
                'data' => [
                    'list' => [
                        [
                            'id' => 9,
                            'task_type' => 'server_reinstall',
                            'status' => 'exec',
                            'progress_percent' => 40,
                            'progress_text' => '正在写入镜像',
                            'mirror_os_name' => 'Ubuntu 22.04',
                        ],
                        ['id' => 8, 'task_type' => 'server_reinstall', 'status' => 'finish'],
                    ],
                    'count' => 2,
                ],
            ]),
        ]);

        $task = $this->operations()->taskStatus(88);

        $this->assertTrue($task['running']);
        $this->assertSame(0, $task['task_type']);
        $this->assertSame(40, $task['progress']);
        $this->assertSame('正在写入镜像', $task['step']);
        $this->assertSame('Ubuntu 22.04', $task['name']);
        $this->assertSame('', $task['error']);

        // 任务列表的固定查询口径（契约 §5.5）
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'limit=20')
            && str_contains($request->url(), 'orderby=id')
            && str_contains($request->url(), 'sort=desc'));
    }

    public function test_traffic_usage_computes_percent_and_sums_packages(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/traffic/usage*' => Http::response([
                'status' => 200,
                'data' => ['used_traffic' => 2147483648, 'limit_flow' => 100],
            ]),
            'https://cloud.test/api/v1/server/88/traffic/package*' => Http::response([
                'status' => 200,
                'data' => [
                    'list' => [
                        ['id' => 1, 'quota_gb' => 50, 'status' => 'normal', 'is_permanent' => 1],
                        ['id' => 2, 'quota_gb' => 10, 'status' => 'normal', 'expire_time' => time() + 86400],
                        ['id' => 3, 'quota_gb' => 99, 'status' => 'expired'],
                    ],
                    'count' => 3,
                ],
            ]),
        ]);

        $usage = $this->operations()->trafficUsage(88);

        $this->assertSame(2.0, $usage['used']);
        $this->assertSame(100, $usage['limit']);
        // 过期包不计入：50 + 10
        $this->assertSame(60, $usage['extra']);
        $this->assertSame(2.0, $usage['percent']);
    }

    public function test_failed_envelope_throws_cloud_api_exception(): void
    {
        Http::fake([
            'https://cloud.test/api/v1/server/88/power_off' => Http::response([
                'status' => 500,
                'msg' => '实例正在执行任务，无法关机',
            ]),
        ]);

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('实例正在执行任务，无法关机');

        $this->operations()->power('off', 88);
    }

    private function operations(): DcimsCloudOperations
    {
        return new DcimsCloudOperations(new DcimsCloudClient(new CloudCredentials(
            supplierId: 1,
            baseUrl: self::BASE_URL,
            account: 'appid',
            secret: 'secret',
            mode: CloudApiMode::Dcims,
        )));
    }

    /**
     * 插件命名空间（`TuraIDC\Plugins\…`）不在 composer autoload 里，由插件运行时注册；
     * 单测直接按依赖顺序 require 插件 lib，避免依赖「插件已安装」这一状态。
     */
    private function loadPluginLib(): void
    {
        $dir = dirname(__DIR__, 2).'/plugins/servers/mofang_cloud/lib';

        foreach ([
            'CloudApiMode.php',
            'CloudApiException.php',
            'CloudCredentials.php',
            'CloudApiClient.php',
            'CloudOperations.php',
            'DcimsCloudClient.php',
            'DcimsCloudOperations.php',
            'LegacyCloudClient.php',
        ] as $file) {
            require_once $dir.'/'.$file;
        }
    }
}
