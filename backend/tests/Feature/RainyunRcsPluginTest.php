<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Contracts\ProvidesBrowserConsole;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunActions;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunApiException;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunCatalog;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunClient;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunConsole;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunLifecycle;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunPanel;
use TuraIDC\Plugins\Servers\RainyunRcs\Lib\RainyunPurchases;
use TuraIDC\Plugins\Servers\RainyunRcs\Logic\RainyunRcs;

class RainyunRcsPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $manifest = app(PluginScanner::class)->find('upstream', 'rainyun_rcs');
        $this->assertNotNull($manifest, '插件清单必须可被平台扫描');
        app(PluginFileLoader::class)->ensureLoaded($manifest);
        Http::preventStrayRequests();
    }

    public function test_manifest_and_runtime_explicitly_opt_in_to_browser_console(): void
    {
        $driver = new RainyunRcs;
        $this->assertTrue($driver->supports(ProvidesBrowserConsole::class));
        $manifest = app(PluginScanner::class)->find('upstream', 'rainyun_rcs');
        $this->assertSame($driver->capabilities(), $manifest->capabilities);
        $this->assertSame('rainyun_rcs', $driver->key());
    }

    public function test_trial_cycle_sets_trial_flag_without_changing_paid_cycles(): void
    {
        $client = new RainyunClient;
        $catalog = new RainyunCatalog($client);
        $lifecycle = new RainyunLifecycle($client, $catalog, new RainyunConsole($client, $catalog));
        foreach (['ontrial', 'trial', 'on_trial'] as $cycle) {
            $payload = $lifecycle->provisionPayload(['os_id' => 535, 'try' => false], 1344, $cycle);
            $this->assertTrue($payload['try']);
            $this->assertSame(1, $payload['duration']);
            $this->assertSame(0, $payload['with_eip_num']);
        }
        $paid = $lifecycle->provisionPayload(['os_id' => 535], 1344, 'quarterly');
        $this->assertFalse($paid['try']);
        $this->assertSame(3, $paid['duration']);
        foreach (['home_ip', 'native'] as $flags) {
            $payload = $lifecycle->provisionPayload(['os_id' => 535, 'with_eip_num' => 1, 'with_eip_flags' => $flags], 1344, 'ontrial');
            $this->assertTrue($payload['try']);
            $this->assertSame($flags, $payload['with_eip_flags']);
            $this->assertSame(1, $payload['with_eip_num']);
        }
        Http::assertNothingSent();
    }

    public function test_reinstall_stops_then_waits_before_changing_os(): void
    {
        $seen = [];
        $details = 0;
        $waits = [];
        Http::fake(function (Request $r) use (&$seen, &$details) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $seen[] = [$r->method(), $path];
            $this->assertSame(['fixture-key'], $r->header('x-api-key'));
            if ($path === '/product/rcs/42/') {
                return $this->ok($this->detail(['running', 'stopping', 'stopped'][$details++]));
            }
            if ($path === '/product/rcs/os-templates') {
                return $this->ok([$this->image()]);
            }
            if ($path === '/product/rcs/42/changeos') {
                $this->assertSame(3, $details);
                $this->assertSame(['os_id' => 146], $r->data());
            }

            return $this->ok(null);
        });
        $driver = new RainyunRcs(wait: function ($seconds) use (&$waits) {
            $waits[] = $seconds;
        });
        $result = $driver->reinstall($this->supplier(), 42, '146');
        $this->assertTrue($result['data']['submitted']);
        $this->assertSame([2, 2], $waits);
        $this->assertSame([
            ['GET', '/product/rcs/42/'], ['GET', '/product/rcs/os-templates'],
            ['POST', '/product/rcs/42/stop'], ['GET', '/product/rcs/42/'],
            ['GET', '/product/rcs/42/'], ['POST', '/product/rcs/42/changeos'],
        ], $seen);
    }

    public function test_invalid_image_cannot_stop_instance(): void
    {
        Http::fake(['*/os-templates' => $this->ok([$this->image()]), '*/42/' => $this->ok($this->detail())]);
        try {
            (new RainyunRcs)->reinstall($this->supplier(), 42, '999');
            $this->fail('无效镜像必须拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('镜像不可用于', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_stop_timeout_never_submits_changeos(): void
    {
        Http::fake(['*/os-templates' => $this->ok([$this->image()]), '*/42/' => $this->ok($this->detail()), '*/stop' => $this->ok(null)]);
        try {
            (new RainyunRcs(wait: static fn () => null))->reinstall($this->supplier(), 42, '146');
            $this->fail('未关机必须拒绝提交重装');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('等待超时', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'changeos'));
    }

    public function test_already_stopped_instance_does_not_send_stop_again(): void
    {
        Http::fake(['*/os-templates' => $this->ok([$this->image()]), '*/42/' => $this->ok($this->detail('stopped')), '*/changeos' => $this->ok(null)]);
        (new RainyunRcs)->reinstall($this->supplier(), 42, '146');
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/stop'));
    }

    public function test_both_console_types_preserve_official_signed_url(): void
    {
        $url = 'https://rack-vnc.raincs.cn/login?node=fixture&data=abc%2Bdef%3D';
        Http::fake(fn (Request $r) => $this->ok(['VNCProxyURL' => $url]));
        $driver = new RainyunRcs;
        foreach (['novnc', 'xtermjs'] as $type) {
            $this->assertSame($url, $driver->browserConsole($this->supplier(), 42, $type)['data']['url']);
            Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), 'console_type='.$type));
        }
        Http::assertSentCount(2);
    }

    public function test_untrusted_console_address_is_rejected(): void
    {
        Http::fake(['*' => $this->ok(['VNCProxyURL' => 'https://raincs.cn.attacker.invalid/login'])]);
        $this->expectException(BusinessException::class);
        (new RainyunRcs)->getVncUrl($this->supplier(), 42);
    }

    public function test_empty_key_refuses_all_requests(): void
    {
        Http::fake();
        $supplier = $this->supplier();
        $supplier->api_key = '';
        try {
            (new RainyunClient)->detail($supplier, 42);
            $this->fail('密钥缺省必须拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('密钥', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_required_ip_error_reports_configuration_requirement(): void
    {
        Http::fake(['*' => Http::response(['code' => 70033, 'message' => '本区域的产品至少需要一个IP'], 400)]);
        try {
            (new RainyunClient)->request($this->supplier(), 'POST', '/product/rcs/', ['try' => true]);
            $this->fail('缺少必须的 IP 应拒绝开通');
        } catch (RainyunApiException $e) {
            $this->assertSame(70033, $e->upstreamCode);
            $this->assertFalse($e->uncertain);
            $this->assertStringContainsString('至少需要一个独立 IP', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_upstream_errors_use_neutral_messages_and_preserve_error_codes(): void
    {
        Http::fakeSequence()
            ->push(['code' => 10005, 'message' => '雨云原始错误'], 400)
            ->push(['code' => 70099, 'message' => '雨云原始错误'], 400);
        foreach ([10005 => '实例或资源不存在，请核对绑定', 70099 => '操作失败，请检查接口权限、参数和账户状态（错误码 70099）'] as $code => $message) {
            try {
                (new RainyunClient)->request($this->supplier(), 'POST', '/product/rcs/42/start');
                $this->fail('上游错误必须转换为业务异常');
            } catch (RainyunApiException $e) {
                $this->assertSame($code, $e->upstreamCode);
                $this->assertSame($message, $e->getMessage());
                $this->assertFalse($e->uncertain);
            }
        }
        Http::assertSentCount(2);
    }

    public function test_detail_must_match_bound_host(): void
    {
        Http::fake(['*' => $this->ok($this->detail())]);
        $this->expectException(BusinessException::class);
        (new RainyunClient)->detail($this->supplier(), 43);
    }

    public function test_foreign_backup_and_disk_are_rejected(): void
    {
        foreach (['backup_restore', 'snapshot_delete', 'delNatAcl'] as $action) {
            try {
                (new RainyunActions(new RainyunClient))->requestFor($action, ['id' => 99], $this->detail());
                $this->fail('外部资源必须拒绝');
            } catch (BusinessException $e) {
                $this->assertStringContainsString('不属于当前实例', $e->getMessage());
            }
        }
        $this->expectException(BusinessException::class);
        $this->purchases()->quote($this->detail(), $this->prices(), ['kind' => 'disk', 'operation' => 'delete', 'disk_id' => 99], 1000);
    }

    public function test_disk_quote_uses_integer_cents_and_31_day_proration(): void
    {
        $detail = $this->detail();
        $detail['Data']['ExpDate'] = 1000 + 31 * 86400;
        $quote = $this->purchases()->quote($detail, $this->prices(), ['kind' => 'disk', 'operation' => 'create', 'disk_type' => 'ssd', 'size' => 50, 'backup' => true], 1000);
        $this->assertSame(1500, $quote['cents']);
        $this->assertSame(1500, $quote['monthly_delta']);
        $this->assertSame('create', $quote['body']['actions'][0]['type']);
    }

    public function test_disk_shrink_and_system_disk_delete_are_rejected(): void
    {
        foreach ([['operation' => 'expand', 'size' => 20], ['operation' => 'delete']] as $input) {
            try {
                $this->purchases()->quote($this->detail(), $this->prices(), array_merge(['kind' => 'disk', 'disk_id' => 1], $input), 1000);
                $this->fail('系统盘删除或缩容必须拒绝');
            } catch (BusinessException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_panel_omits_host_credentials_and_escapes_script_payloads(): void
    {
        $detail = $this->detail();
        $detail['Data']['DefaultPass'] = 'never-display-fixture';
        $detail['RBSList'] = [['id' => 1, 'label' => '</script><script>alert(1)</script>']];
        $html = (new RainyunPanel)->render($detail, 'backups', 'https://api.example.invalid/action');
        $this->assertStringNotContainsString('never-display-fixture', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }

    private function supplier(): Supplier
    {
        return (new Supplier)->forceFill(['id' => 7, 'api_url' => 'https://api.example.invalid', 'api_key' => 'fixture-key']);
    }

    private function image(): array
    {
        return ['id' => 146, 'is_available' => true, 'region' => 'test', 'subtype' => 'kvm', 'machine' => 'amd', 'chinese_name' => 'Debian 11'];
    }

    private function detail(string $status = 'running'): array
    {
        return ['Data' => ['ID' => 42, 'Status' => $status, 'PlanID' => 10, 'ExpDate' => 9999999, 'Try' => false,
            'Plan' => ['region' => 'test', 'subtype' => 'kvm', 'machine' => 'amd', 'disk_size' => ['ssd' => 30]]],
            'AvailableDiskType' => ['ssd'], 'EDiskList' => [['ID' => 1, 'Slot' => 0, 'DiskType' => 'ssd', 'Size' => 30, 'Backup' => false]],
            'NatList' => [], 'RBSList' => [], 'SnapshotList' => []];
    }

    private function prices(): array
    {
        return ['disk_ssd_price' => '0.20', 'disk_backup_price' => '0.10', 'traffic_price' => '0.02'];
    }

    private function purchases(): RainyunPurchases
    {
        $client = new RainyunClient;

        return new RainyunPurchases($client);
    }

    private function ok(mixed $data): PromiseInterface
    {
        return Http::response(['code' => 200, 'data' => $data]);
    }
}
