<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_provision_posts_auto_assign_then_installs_system(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/auto_assign' => Http::response([
                'status' => 200,
                'msg' => 'ok',
                'data' => ['id' => 501, 'username' => 'root'],
            ]),
            self::BASE_URL.'/api/v1/server/501/reinstall' => Http::response(['status' => 200, 'msg' => '已下发']),
        ]);

        $result = $this->operations()->provision([
            'product_id' => 12,
            'hostname' => 'web-01',
            'password' => '',
            'os_id' => 7,
            'area_id' => 3,
            'cpu' => 4,
            'memory' => 8192,
            'disk' => 100,
            'bandwidth' => 50,
            'flow' => 1000,
            'ip_num' => 2,
            'ipv6_num' => 0,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_options' => ['server_group_id' => 9],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(501, $result['host_id']);
        $this->assertStringContainsString('系统安装', $result['message']);
        $this->assertArrayHasKey('auto_assign', $result['raw']);
        $this->assertArrayHasKey('reinstall', $result['raw']);

        /** @var array<int, array{0: Request, 1: mixed}> $recorded */
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded, '开通后必须追加一次 reinstall');

        [$assign, $install] = [$recorded[0][0], $recorded[1][0]];

        $this->assertSame('POST', $assign->method());
        $this->assertSame(self::BASE_URL.'/api/v1/server/auto_assign', $assign->url());

        $payload = json_decode($assign->body(), true);
        $this->assertIsArray($payload);
        $this->assertSame(9, $payload['server_group_id'] ?? null);
        $this->assertSame(7, $payload['mirror_os_id'] ?? null);
        $this->assertSame(12, $payload['product_id'] ?? null);
        $this->assertSame(50, $payload['in_bw'] ?? null);
        $this->assertSame(50, $payload['out_bw'] ?? null);
        $this->assertSame(1000, $payload['limit_flow'] ?? null);
        $this->assertSame([['ip_group_id' => 0, 'num' => 2]], $payload['ip'] ?? null);

        // 新版接口不认的键（以及值为 0 的 ipv6_num）一律不进报文
        foreach (['cpu', 'memory', 'disk', 'hostname', 'billing_cycle', 'quantity', 'ipv6_num', 'client', 'traffic_reset'] as $absent) {
            $this->assertArrayNotHasKey($absent, $payload);
        }

        $this->assertSame('POST', $install->method());
        $this->assertSame(self::BASE_URL.'/api/v1/server/501/reinstall', $install->url());

        $installPayload = json_decode($install->body(), true);
        $this->assertIsArray($installPayload);
        $this->assertSame('501', $installPayload['id'] ?? null);
        $this->assertSame(501, $installPayload['product_id'] ?? null);
        $this->assertSame(7, $installPayload['mirror_os_id'] ?? null);
        $this->assertSame(22, $installPayload['remote_port'] ?? null);
        $this->assertSame('bios', $installPayload['boot_mode'] ?? null);
        $this->assertSame('default', $installPayload['partition_mode'] ?? null);
        $this->assertSame('full', $installPayload['format_type'] ?? null);
        // 下单没给密码、上游也没回：必须兜底生成一个，否则实例装完无法登录
        $this->assertNotSame('', (string) ($installPayload['os_password'] ?? ''));
    }

    public function test_provision_keeps_ok_when_system_install_fails(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/auto_assign' => Http::response([
                'status' => 200,
                'data' => ['id' => 502],
            ]),
            self::BASE_URL.'/api/v1/server/502/reinstall' => Http::response([
                'status' => 500,
                'msg' => '镜像不可用',
            ]),
        ]);

        $result = $this->operations()->provision([
            'os_id' => 7,
            'area_id' => 3,
            'password' => 'Passw0rd!',
        ]);

        $this->assertTrue($result['ok'], '补装失败不改变开通结论');
        $this->assertSame(502, $result['host_id']);
        $this->assertStringContainsString('补装系统失败', $result['message']);
        $this->assertStringContainsString('镜像不可用', $result['message']);
    }

    public function test_provision_requires_server_group_and_image_without_sending_requests(): void
    {
        Http::fake();

        $operations = $this->operations();

        try {
            $operations->provision(['os_id' => 7]);
            $this->fail('缺少服务器分组时必须报错');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('服务器分组', $exception->getMessage());
        }

        try {
            $operations->provision(['area_id' => 3]);
            $this->fail('缺少镜像时必须报错');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('操作系统镜像', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_buy_flow_packet_posts_permanent_package(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/traffic/package' => Http::response(['status' => 200, 'msg' => '已加量']),
        ]);

        $result = $this->operations()->buyFlowPacket(88, 200);

        $this->assertTrue($result['ok']);
        $this->assertSame('已加量', $result['message']);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL.'/api/v1/server/88/traffic/package'
                && is_array($payload)
                && ($payload['id'] ?? null) === '88'
                && ($payload['product_id'] ?? null) === 88
                && ($payload['quota_gb'] ?? null) === 200
                && ($payload['expiry_type'] ?? null) === 'permanent';
        });

        // 0 GB 的流量包没有意义，直接拒绝且不发请求
        try {
            $this->operations()->buyFlowPacket(88, 0);
            $this->fail('0 GB 必须报错');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('大于 0', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_provision_sends_client_ownership_block(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/auto_assign' => Http::response(['status' => 200, 'data' => ['id' => 503]]),
            self::BASE_URL.'/api/v1/server/503/reinstall' => Http::response(['status' => 200]),
        ]);

        $operations = $this->operations();

        $operations->provision([
            'os_id' => 7,
            'area_id' => 3,
            'client_username' => 'pre9',
            'client_user_id' => 42,
        ]);

        $payload = json_decode(Http::recorded()[0][0]->body(), true);
        $this->assertIsArray($payload);
        $this->assertSame('pre9', $payload['client']['username'] ?? null);
        $this->assertSame(42, $payload['client']['rel_user_id'] ?? null);
        $this->assertSame('finance', $payload['client']['from_channel'] ?? null);

        // client_username 为空：整块省略，不用空串占位（只有 client_user_id 也不写）
        $operations->provision([
            'os_id' => 7,
            'area_id' => 3,
            'client_username' => '',
            'client_user_id' => 42,
        ]);

        $second = json_decode(Http::recorded()[2][0]->body(), true);
        $this->assertIsArray($second);
        $this->assertArrayNotHasKey('client', $second);
    }

    public function test_change_config_sends_only_the_requested_endpoint(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth' => Http::response(['status' => 200, 'msg' => 'ok']),
        ]);

        $result = $this->operations()->changeConfig(88, ['in_bw' => 200]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['in_bw'], $result['applied']);
        $this->assertSame('已调整：入方向带宽', $result['message']);

        /** @var array<int, array{0: Request, 1: mixed}> $recorded */
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, '只改带宽时不应该顺手打其它端点');

        $request = $recorded[0][0];
        $this->assertSame('PUT', $request->method());
        $this->assertSame(self::BASE_URL.'/api/v1/server/88/in_bandwidth', $request->url());

        $payload = json_decode($request->body(), true);
        $this->assertIsArray($payload);
        $this->assertSame(200, $payload['in_bw'] ?? null);
        $this->assertSame('88', $payload['id'] ?? null);
        $this->assertSame(88, $payload['product_id'] ?? null);
    }

    public function test_change_config_sends_all_supported_endpoints_in_fixed_order(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth' => Http::response(['status' => 200]),
            self::BASE_URL.'/api/v1/server/88/out_bandwidth' => Http::response(['status' => 200]),
            self::BASE_URL.'/api/v1/server/88/limit_flow' => Http::response(['status' => 200]),
            self::BASE_URL.'/api/v1/server/88/ip' => Http::response(['status' => 200]),
        ]);

        $result = $this->operations()->changeConfig(88, [
            'in_bw' => 100,
            'out_bw' => 200,
            'flow' => 500,
            'ip_num' => 2,
        ]);

        $this->assertSame(['in_bw', 'out_bw', 'flow', 'ip_num'], $result['applied']);
        $this->assertSame('已调整：入方向带宽、出方向带宽、流量上限、IPv4 数量', $result['message']);

        /** @var array<int, array{0: Request, 1: mixed}> $recorded */
        $recorded = Http::recorded()->all();
        $this->assertCount(4, $recorded);

        $this->assertSame([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth',
            self::BASE_URL.'/api/v1/server/88/out_bandwidth',
            self::BASE_URL.'/api/v1/server/88/limit_flow',
            self::BASE_URL.'/api/v1/server/88/ip',
        ], array_map(static fn (array $pair): string => $pair[0]->url(), $recorded));

        $payloads = array_map(static fn (array $pair): array => (array) json_decode($pair[0]->body(), true), $recorded);

        $this->assertSame(100, $payloads[0]['in_bw'] ?? null);
        $this->assertSame(200, $payloads[1]['out_bw'] ?? null);
        $this->assertSame(500, $payloads[2]['limit_flow'] ?? null);
        $this->assertSame([['ip_group_id' => 0, 'num' => 2]], $payloads[3]['ip'] ?? null);

        // 每个报文都必须带 id（签名要求 path 与 body 一致）
        foreach ($payloads as $payload) {
            $this->assertSame('88', $payload['id'] ?? null);
        }
    }

    public function test_change_config_uses_the_given_ip_group(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/ip' => Http::response(['status' => 200]),
        ]);

        $result = $this->operations()->changeConfig(88, ['ip_num' => 3, 'ip_group' => 7]);

        $this->assertSame(['ip_num'], $result['applied']);

        $payload = json_decode(Http::recorded()[0][0]->body(), true);
        $this->assertIsArray($payload);
        $this->assertSame([['ip_group_id' => 7, 'num' => 3]], $payload['ip'] ?? null);
    }

    public function test_change_config_ignores_ip_group_without_ip_num(): void
    {
        Http::fake();

        // 只有地址组、没有数量，不构成一次调整 → 与「什么都没指定」同一语义：抛错且零请求
        try {
            $this->operations()->changeConfig(88, ['ip_group' => 5]);
            $this->fail('没有可提交项时必须报错');
        } catch (CloudApiException $exception) {
            $this->assertSame('改配失败：没有可提交的变更项', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_change_config_rejects_unsupported_only_request_without_requests(): void
    {
        Http::fake();

        try {
            $this->operations()->changeConfig(88, ['cpu' => 8]);
            $this->fail('只请求不支持的项时必须报错');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('不支持调整', $exception->getMessage());
            $this->assertStringContainsString('CPU', $exception->getMessage());
            $this->assertStringContainsString('请到魔方云面板操作', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_change_config_applies_supported_part_and_reports_skipped_items(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth' => Http::response(['status' => 200]),
        ]);

        $result = $this->operations()->changeConfig(88, [
            'in_bw' => 50,
            'memory' => 8192,
            'disk_size' => 200,
        ]);

        $this->assertSame(['in_bw'], $result['applied']);
        $this->assertStringContainsString('已调整：入方向带宽', $result['message']);
        $this->assertStringContainsString('未提交', $result['message']);
        $this->assertStringContainsString('内存', $result['message']);
        $this->assertStringContainsString('磁盘容量', $result['message']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_change_config_rejects_empty_request_without_requests(): void
    {
        Http::fake();

        // 全是 null/''/0：一次可提交的变更都没有，与老版实现同一语义（改配失败），不发任何请求
        try {
            $this->operations()->changeConfig(88, [
                'in_bw' => 0,
                'out_bw' => 0,
                'flow' => 0,
                'ip_num' => 0,
                'cpu' => 0,
            ]);
            $this->fail('没有可提交项时必须报错');
        } catch (CloudApiException $exception) {
            $this->assertSame('改配失败：没有可提交的变更项', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_change_config_reports_applied_items_when_a_later_endpoint_fails(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth' => Http::response(['status' => 200]),
            self::BASE_URL.'/api/v1/server/88/out_bandwidth' => Http::response([
                'status' => 500,
                'msg' => '带宽超过套餐上限',
            ]),
        ]);

        try {
            $this->operations()->changeConfig(88, ['in_bw' => 100, 'out_bw' => 9999]);
            $this->fail('第二个端点失败时必须抛异常');
        } catch (CloudApiException $exception) {
            // 管理员要知道「入方向已经改了」，不能只看到一句出方向失败就以为整单没生效
            $this->assertSame(
                '出方向带宽调整失败（入方向带宽已生效）：带宽超过套餐上限',
                $exception->getMessage()
            );
        }

        $this->assertCount(2, Http::recorded());
    }

    public function test_change_config_has_no_applied_prefix_when_the_first_endpoint_fails(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/server/88/in_bandwidth' => Http::response(['status' => 500, 'msg' => '不允许调小']),
        ]);

        try {
            $this->operations()->changeConfig(88, ['in_bw' => 10, 'out_bw' => 20]);
            $this->fail('第一个端点失败时必须抛异常');
        } catch (CloudApiException $exception) {
            $this->assertSame('入方向带宽调整失败：不允许调小', $exception->getMessage());
        }

        // 第一项就失败：后续端点不该再发
        $this->assertCount(1, Http::recorded());
    }

    /**
     * 新版本接口没有这些端点：必须抛中文异常，且一个请求都不发。
     *
     * 用闭包而不是「方法名字符串 + 动态调用」，这样参数个数/类型由 PHP 直接校验，
     * 将来接口签名变化时用例会当场报错而不是悄悄跑空。
     *
     * @return array<string, array{0: callable(DcimsCloudOperations): array}>
     */
    public static function unsupportedCapabilityProvider(): array
    {
        return [
            'snapshots' => [static fn (DcimsCloudOperations $operations): array => $operations->snapshots(88)],
            'createSnapshot' => [static fn (DcimsCloudOperations $operations): array => $operations->createSnapshot(88, ['name' => 'snap-1'])],
            'deleteSnapshot' => [static fn (DcimsCloudOperations $operations): array => $operations->deleteSnapshot(88, 5)],
            'restoreSnapshot' => [static fn (DcimsCloudOperations $operations): array => $operations->restoreSnapshot(88, 5)],
            'isoImages' => [static fn (DcimsCloudOperations $operations): array => $operations->isoImages(88)],
            'mountIso' => [static fn (DcimsCloudOperations $operations): array => $operations->mountIso(88, 'iso-1')],
            'unmountIso' => [static fn (DcimsCloudOperations $operations): array => $operations->unmountIso(88)],
            'setBootOrder' => [static fn (DcimsCloudOperations $operations): array => $operations->setBootOrder(88, 'disk')],
            'securityRules' => [static fn (DcimsCloudOperations $operations): array => $operations->securityRules(88, 3)],
            'createSecurityGroup' => [static fn (DcimsCloudOperations $operations): array => $operations->createSecurityGroup(88, ['name' => 'sg'])],
            'deleteSecurityGroup' => [static fn (DcimsCloudOperations $operations): array => $operations->deleteSecurityGroup(88, 3)],
            'createSecurityRule' => [static fn (DcimsCloudOperations $operations): array => $operations->createSecurityRule(88, 3, ['protocol' => 'tcp'])],
            'deleteSecurityRule' => [static fn (DcimsCloudOperations $operations): array => $operations->deleteSecurityRule(88, 3, 9)],
            'applySecurityGroup' => [static fn (DcimsCloudOperations $operations): array => $operations->applySecurityGroup(88, 3)],
        ];
    }

    /**
     * @param  callable(DcimsCloudOperations): array  $call
     */
    #[DataProvider('unsupportedCapabilityProvider')]
    public function test_unsupported_capabilities_throw_without_sending_requests(callable $call): void
    {
        Http::fake();

        try {
            $call($this->operations());
            $this->fail('新版本接口没有该端点，必须抛出 CloudApiException');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('新版本接口', $exception->getMessage());
            $this->assertStringContainsString('请到魔方云面板操作', $exception->getMessage());
        }

        Http::assertNothingSent();
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
