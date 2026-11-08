<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiException;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiMode;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudCredentials;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\LegacyCloudClient;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\LegacyCloudOperations;

/**
 * 魔方云老版本（legacy /v1）能力实现的协议映射回归。
 *
 * 覆盖三类风险：
 * 1. 传输层契约：`{base}/v1{action}`、`access-token` 头、登录取「带引号 token 原文」；
 * 2. 平响应的解析：详情字段在顶层、列表在 `data`、状态端点的 `status` 是运行状态而不是成功标志；
 * 3. 老接口的能力缺口与串行依赖：取消任务不支持、安全组要先查详情拿 user_id。
 */
class MofangCloudLegacyOperationsTest extends TestCase
{
    private const BASE = 'https://cloud.test';

    private const TOKEN = 'legacy-token-abc';

    /** @var array<string, mixed> 本轮用例的上游响应表（按 URL 片段匹配） */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 插件命名空间（TuraIDC\Plugins\…）不在 composer autoload 里，由插件运行时加载；
        // 这里直接 require 插件 lib 目录，避免测试依赖「插件已安装」这一状态
        $this->loadPluginLib();

        // token 缓存 key 含 supplierId，用例之间必须清干净，否则先跑的先赢
        Cache::flush();

        // 只注册一次 stub，用例内用 fakeUpstream() 换响应表（fake() 会追加而不是替换）
        Http::fake(fn (Request $request) => $this->dispatchFake($request));
    }

    public function test_power_posts_to_legacy_endpoint_with_access_token_header(): void
    {
        $this->fakeUpstream([
            '/v1/login?a=a' => '"'.self::TOKEN.'"',
            '/v1/clouds/88/on' => ['status' => 'success', 'msg' => '开机成功'],
        ]);

        $result = $this->operations()->power('on', 88);

        $this->assertTrue($result['ok']);
        $this->assertSame('开机成功', $result['message']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/clouds/88/on')
            && $request->hasHeader('access-token', self::TOKEN));

        // 登录用的是带引号原文，客户端必须把引号去掉再用
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/login?a=a')
            && ($request['username'] ?? '') === 'u'
            && ($request['password'] ?? '') === 'p');
    }

    public function test_power_rejects_unknown_action_before_any_request(): void
    {
        Http::fake();

        try {
            $this->operations()->power('suspend', 88);
            $this->fail('未知电源动作必须直接拒绝');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('不支持的电源操作', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_host_detail_normalizes_top_level_fields(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88' => [
                'id' => 88,
                'hostname' => 'web1.example.test',
                'status' => 'on',
                'mainip' => '1.2.3.4',
                'ip' => [
                    ['ipaddress' => '5.6.7.8', 'remark' => '备用,线路2'],
                    ['ipaddress' => '1.2.3.4'],
                ],
                'ipv6' => [['ipv6' => '2001:db8::1']],
                'client_show_ip_remark' => 1,
                'traffic_quota' => 100,
                'tmp_traffic' => 20,
                'cpu' => 4,
                'memory' => 8192,
                'osuser' => 'root',
                'rootpassword' => 's3cret',
                'port' => 22,
                'node_id' => 7,
                'kvmid' => 'kvm-88',
                'system' => 'CentOS 7',
                'create_time' => '2026-01-02 03:04:05',
                'rescue' => 0,
                'iso' => '',
                'bootorder' => 'c',
                'disk' => [
                    ['id' => 11, 'size' => 40, 'type' => 'system'],
                    ['id' => 12, 'size' => 100, 'type' => 'data'],
                ],
            ],
        ]);

        $detail = $this->operations()->hostDetail(88);

        // 详情是平响应：字段直接在顶层，不需要 status/msg/data 信封
        $this->assertSame('web1.example.test', $detail['domain']);
        $this->assertSame('on', $detail['domainstatus']);
        $this->assertSame('1.2.3.4', $detail['dedicatedip']);
        $this->assertSame(100, $detail['bwlimit']);
        $this->assertSame(20, $detail['bwlimit_included']);
        $this->assertSame(4, $detail['cpu']);
        $this->assertSame(8192, $detail['memory']);
        $this->assertSame('root', $detail['username']);
        $this->assertSame('s3cret', $detail['password']);
        $this->assertSame(22, $detail['port']);
        $this->assertSame(7, $detail['node_id']);
        $this->assertSame('kvm-88', $detail['kvmid']);
        $this->assertSame('CentOS 7', $detail['os']);
        $this->assertSame('2026-01-02 03:04:05', $detail['created_at']);
        $this->assertSame(0.0, $detail['bwusage']);

        // 附加 IP：备注按显示开关拼接、主 IP 剔除、IPv6 追加
        $this->assertContains('5.6.7.8(备用，线路2)', $detail['assignedips']);
        $this->assertContains('2001:db8::1', $detail['assignedips']);
        $this->assertNotContains('1.2.3.4', $detail['assignedips']);

        $this->assertCount(2, $detail['disk']);
        $this->assertSame('data', $detail['disk'][1]['type']);
        $this->assertSame(100.0, $detail['disk'][1]['size']);
    }

    public function test_host_status_maps_running_states(): void
    {
        $cases = [
            'wait_reboot' => 'wait_reboot',
            'task' => 'task',
            'cold_migrate' => 'task',
            'on' => 'on',
            'something_new' => 'unknown',
        ];

        foreach ($cases as $raw => $expected) {
            $this->seedToken();
            $this->fakeUpstream([
                '/v1/clouds/88/status' => [
                    'status' => $raw,
                    'task' => $raw === 'task' ? 'reinstall' : '',
                    'task_name' => $raw === 'task' ? '重装系统' : '',
                ],
            ]);

            $status = $this->operations()->hostStatus(88);

            $this->assertSame($expected, $status['status'], '状态「'.$raw.'」映射错误');
        }

        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88/status' => ['status' => 'task', 'task' => 'reinstall', 'task_name' => '重装系统'],
        ]);

        $status = $this->operations()->hostStatus(88);
        $this->assertSame('reinstall', $status['task']);
        $this->assertSame('重装系统', $status['task_name']);
    }

    public function test_security_groups_fetches_detail_first_then_list(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88' => ['id' => 88, 'user_id' => 12, 'status' => 'on'],
            '/v1/security_groups' => ['data' => [['id' => 3, 'name' => 'sg-a', 'description' => '']]],
        ]);

        $groups = $this->operations()->securityGroups(88);

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['id']);

        // 只有详情 + 列表两次请求：不会去查全局安全组
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/security_groups?')
            && str_contains($request->url(), 'user=12')
            && str_contains($request->url(), 'list_type=all'));
    }

    public function test_cancel_task_is_not_supported_on_legacy_api(): void
    {
        Http::fake();

        try {
            $this->operations()->cancelTask(88);
            $this->fail('老接口没有取消任务，必须抛业务异常');
        } catch (CloudApiException $exception) {
            $this->assertStringContainsString('不支持取消任务', $exception->getMessage());
            $this->assertSame(CloudApiMode::Legacy, $exception->mode());
            $this->assertInstanceOf(BusinessException::class, $exception);
        }

        Http::assertNothingSent();
    }

    public function test_reinstall_returns_new_credentials_from_upstream(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88/reinstall' => [
                'status' => 'success',
                'msg' => '重装发起成功',
                'data' => ['password' => 'NewPass9', 'user' => 'root'],
            ],
        ]);

        $result = $this->operations()->reinstall(88, ['os_id' => 7, 'port' => 2222, 'format_data_disk' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame('NewPass9', $result['password']);
        $this->assertSame('root', $result['username']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/v1/clouds/88/reinstall')
            && (string) ($request['os'] ?? '') === '7'
            && (string) ($request['port'] ?? '') === '2222'
            && (string) ($request['format_data_disk'] ?? '') === '1');
    }

    public function test_nat_rules_inject_host_ip_and_delete_passes_hostid(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88/nat_acl' => [
                'data' => [['id' => 5, 'ext_port' => 10022, 'int_port' => 22, 'protocol' => 1]],
                'nat_host_ip' => '9.9.9.9',
            ],
            '/v1/nat_acl/5' => ['status' => 200],
        ]);

        $rules = $this->operations()->natRules(88);
        $this->assertCount(1, $rules);
        $this->assertSame('9.9.9.9', $rules[0]['nat_host_ip']);

        $deleted = $this->operations()->deleteNatRule(88, 5);
        $this->assertTrue($deleted['ok']);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v1/nat_acl/5?hostid=88'));
    }

    public function test_terminate_treats_missing_instance_as_deleted(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88' => [404, ['error' => 'Host not found']],
        ]);

        $result = $this->operations()->terminate(88);

        $this->assertTrue($result['ok']);
        $this->assertSame('删除成功', $result['message']);
    }

    public function test_traffic_usage_converts_bytes_to_gb_and_computes_percent(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/net_info' => [
                'meta' => ['traffic_quota' => 100, 'tmp_traffic' => 20, 'traffic_type' => 3],
                'info' => ['30_day' => ['total' => 53687091200, 'accept' => 1, 'send' => 2, 'float' => 0]],
            ],
        ]);

        $usage = $this->operations()->trafficUsage(88);

        // 53687091200 B = 50 GB
        $this->assertSame(50.0, $usage['used']);
        $this->assertSame(100, $usage['limit']);
        $this->assertSame(20, $usage['extra']);
        $this->assertSame(50.0, $usage['percent']);
        $this->assertSame('', $usage['reset_at']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/net_info?host_id=88'));
    }

    public function test_statistics_reads_columns_by_metric(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88' => ['id' => 88, 'node_id' => 7, 'kvmid' => 'kvm-88', 'status' => 'on'],
            '/v1/statistics' => ['data' => [['2026-01-02 03:04:05', 12.5, 30, 40]]],
        ]);

        $cpu = $this->operations()->statistics(88, 'cpu', ['st' => '1700000000', 'et' => '1700003600']);
        $this->assertCount(1, $cpu);
        $this->assertSame(12.5, $cpu[0]['value']);
        $this->assertArrayNotHasKey('value2', $cpu[0]);

        // memory 取第 3、4 列
        $memory = $this->operations()->statistics(88, 'memory', []);
        $this->assertSame(30.0, $memory[0]['value']);
        $this->assertSame(40.0, $memory[0]['value2']);

        // disk 取第 2、3 列，并带上 dev_name
        $disk = $this->operations()->statistics(88, 'disk', ['select' => 'vdb']);
        $this->assertSame(12.5, $disk[0]['value']);
        $this->assertSame(30.0, $disk[0]['value2']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'type=disk_io')
            && str_contains($request->url(), 'dev_name=vdb')
            && str_contains($request->url(), 'node_id=7')
            && str_contains($request->url(), 'kvm=kvm-88'));
    }

    public function test_vnc_prefers_https_url(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88/vnc' => [
                'status' => 'success',
                'vnc_url_http' => 'http://vnc.example.test:6080',
                'vnc_url_https' => 'https://vnc.example.test:6081',
                'vnc_pass' => 'vnc-pass',
                'token' => 'vnc-token',
            ],
        ]);

        $vnc = $this->operations()->vnc(88);

        $this->assertSame('https://vnc.example.test:6081', $vnc['url']);
        $this->assertSame('vnc-token', $vnc['token']);
        $this->assertSame('vnc-pass', $vnc['pass']);
    }

    public function test_write_failure_raises_readable_business_exception(): void
    {
        $this->seedToken();
        $this->fakeUpstream([
            '/v1/clouds/88/off' => ['status' => 'error', 'msg' => '实例正在重装中，暂不可关机'],
        ]);

        $this->expectException(CloudApiException::class);
        $this->expectExceptionMessage('实例正在重装中，暂不可关机');

        $this->operations()->power('off', 88);
    }

    /* --------------------------------- 夹具 --------------------------------- */

    /**
     * 按依赖顺序加载插件 lib（插件类不在 composer autoload 中）。
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
            'LegacyCloudClient.php',
            'LegacyCloudOperations.php',
        ] as $file) {
            require_once $dir.'/'.$file;
        }
    }

    private function credentials(): CloudCredentials
    {
        return new CloudCredentials(
            supplierId: 1,
            baseUrl: self::BASE,
            account: 'u',
            secret: 'p',
            mode: CloudApiMode::Legacy,
        );
    }

    private function operations(): LegacyCloudOperations
    {
        return new LegacyCloudOperations(new LegacyCloudClient($this->credentials()));
    }

    /**
     * 预置 token，避免每个用例都先发一次登录请求（需要精确计数时尤其重要）。
     */
    private function seedToken(): void
    {
        Cache::put($this->credentials()->tokenCacheKey(), self::TOKEN, 3600);
    }

    /**
     * 登记本轮上游响应表。
     *
     * 注意：`Http::fake()` 是**追加** stub 而不是替换，所以 stub 只在 setUp 注册一次，
     * 这里只换数据表；否则同一用例里第二次登记不会生效，会返回上一轮响应。
     *
     * - 字符串直接当响应体（登录接口返回带引号 token 原文）
     * - 关联数组当 JSON 响应体
     * - `[状态码, 响应体]` 形式表达非 2xx（如删除实例返回 404）
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeUpstream(array $routes): void
    {
        $this->routes = $routes;
    }

    /**
     * 唯一的 stub：按 URL 片段分发，命中不到的请求返回可读错误体。
     */
    private function dispatchFake(Request $request): PromiseInterface
    {
        $url = (string) $request->url();

        foreach ($this->routes as $fragment => $response) {
            if (! str_contains($url, (string) $fragment)) {
                continue;
            }

            if (is_array($response) && array_is_list($response) && count($response) === 2 && is_int($response[0])) {
                return Http::response($response[1], $response[0]);
            }

            return Http::response($response, 200);
        }

        return Http::response(['error' => '测试未模拟的请求: '.$request->method().' '.$url], 200);
    }
}
