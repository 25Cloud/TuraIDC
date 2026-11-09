<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\ProviderRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\MofangCloudAdapter;

/**
 * 魔方云插件的上游接入链路：插件清单 → 运行时注册 → 能力解析 → 供应商表单。
 *
 * 这条链路任何一环断了，管理端都选不到这个供应商类型，或者选了之后
 * 平台拿不到能力实现（表现为实例详情/电源按钮全线不可用）。
 *
 * 全程包在事务里：安装插件会写 `integration_plugins`，而该表被
 * `product_upstream_bindings.plugin_id` 外键引用，事务回滚比手工删行可靠。
 */
class MofangCloudProviderWiringTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plugin_scans_and_installs_as_upstream_provider(): void
    {
        $manifest = app(PluginScanner::class)->requireManifest('upstream', 'mofang_cloud');

        $this->assertSame('mofang_cloud_api', $manifest->key);
        $this->assertSame('魔方云接口', $manifest->name);
        $this->assertContains(ProvidesConsoleRuntime::class, $manifest->capabilities);

        $plugin = app(PluginInstaller::class)->install('upstream', 'mofang_cloud');

        if (! $plugin->isEnabled()) {
            $plugin->forceFill(['status' => 1])->save();
        }

        app()->forgetInstance(ProviderRegistry::class);
        $registry = app(ProviderRegistry::class);

        $this->assertContains('mofang_cloud_api', $registry->keys());
    }

    public function test_registered_driver_declares_capabilities_and_resolves_adapter(): void
    {
        $plugin = app(PluginInstaller::class)->install('upstream', 'mofang_cloud');
        if (! $plugin->isEnabled()) {
            $plugin->forceFill(['status' => 1])->save();
        }

        app()->forgetInstance(ProviderRegistry::class);
        $driver = app(ProviderRegistry::class)->find('mofang_cloud_api');

        $this->assertNotNull($driver);
        $this->assertTrue($driver->supports(ProvidesConsoleRuntime::class));
        // 安全组与 NAT 转发都已接原生端点：漏声明这两个标记，控制台会退回
        // 「解析上游 HTML 自定义模块」那条老路，而魔方云根本没有 HTML 模块可解析。
        $this->assertTrue($driver->supports(ProvidesConsoleSecurity::class));
        $this->assertTrue($driver->supports(ProvidesConsoleNetwork::class));
        // 开通能力必须声明：编排层以 ProvidesProvisioning 作为门槛，漏声明会导致
        // 订单付了钱却不进自动开通，只能人工补开。
        $this->assertTrue($driver->supports(ProvidesProvisioning::class));
        $this->assertTrue($driver->supports(ProvidesOrderProvisioning::class));

        $resolved = $driver->resolve(ProvidesConsoleRuntime::class);
        $this->assertInstanceOf(MofangCloudAdapter::class, $resolved);
    }

    public function test_supplier_form_exposes_mode_switch_and_credential_fields(): void
    {
        $plugin = app(PluginInstaller::class)->install('upstream', 'mofang_cloud');
        if (! $plugin->isEnabled()) {
            $plugin->forceFill(['status' => 1])->save();
        }

        app()->forgetInstance(ProviderRegistry::class);
        $descriptor = app(ProviderRegistry::class)->descriptor('mofang_cloud_api');

        $this->assertNotNull($descriptor);

        $fields = (array) ($descriptor->supplierForm['fields'] ?? []);
        $keys = array_map(static fn (mixed $field): string => (string) ($field['key'] ?? ''), $fields);

        // 三个基础凭据字段由平台直接落库，其余落 provider_config。
        $this->assertContains('api_url', $keys);
        $this->assertContains('api_username', $keys);
        $this->assertContains('api_key', $keys);
        // 代际开关是「不能混用」的落点，必须有。
        $this->assertContains('api_mode', $keys);
        $this->assertContains('account_type', $keys);
        // 证书校验不允许由插件开关（仓库级契约），表单里不得出现此类字段。
        $this->assertNotContains('ignore_https_cert', $keys);

        $mode = collect($fields)->firstWhere('key', 'api_mode');
        $this->assertSame('auto', $mode['default']);
        $this->assertSame(
            ['auto', 'legacy', 'dcims'],
            array_map(static fn (array $option): string => (string) $option['value'], $mode['options'])
        );
    }

    /**
     * 供应商卡片要能把「代际分辨」的结果亮出来：连不上时也不许抛异常，
     * 只能把原因写成文案，否则管理端整页卡片会 500。
     */
    public function test_supplier_card_reports_diagnosis_without_throwing(): void
    {
        $plugin = app(PluginInstaller::class)->install('upstream', 'mofang_cloud');
        if (! $plugin->isEnabled()) {
            $plugin->forceFill(['status' => 1])->save();
        }

        $supplier = Supplier::query()->create([
            'name' => '魔方云测试供应商',
            'code' => 'mofang_cloud_wiring_test',
            'status' => 1,
            'sort_order' => 0,
        ]);

        app()->forgetInstance(ProviderRegistry::class);
        $entry = app(PluginRuntimeRegistry::class)->execute('upstream', 'mofang_cloud', 'server.supplier.refresh_card', [], [
            'supplier' => $supplier,
        ]);

        $this->assertTrue((bool) ($entry['success'] ?? false));

        $card = (array) ($entry['data']['card'] ?? []);
        $fieldKeys = array_map(
            static fn (mixed $field): string => (string) ($field['key'] ?? ''),
            (array) ($card['fields'] ?? [])
        );

        $this->assertContains('api_mode', $fieldKeys);
        $this->assertContains('base_url', $fieldKeys);
        $this->assertSame('danger', $card['status']['theme'] ?? null);

        $actions = (array) ($card['actions'] ?? []);
        $this->assertSame('server.supplier.refresh_card', $actions[0]['request_action'] ?? null);
    }
}
