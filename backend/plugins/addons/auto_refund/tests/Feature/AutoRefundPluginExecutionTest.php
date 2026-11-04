<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;

/**
 * 插件入口 `execute()` 只做状态自检。
 *
 * 平台会在插件扫描、启用、后台列表等时机调用它，所以这里最关键的两条是：
 * 1. 绝不发上游请求（Http::assertNothingSent）；
 * 2. 未启用时由平台拒绝（`插件未安装或未启用`），不出现「入口在跑但路由不存在」的半状态。
 */
class AutoRefundPluginExecutionTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsAutoRefundAddon;

    public function test_status_action_reports_configuration_without_calling_upstream(): void
    {
        $this->installAutoRefundAddon();

        Http::fake(function (): never {
            throw new \RuntimeException('execute() 不应发任何上游请求');
        });

        $result = $this->app->make(PluginRuntimeRegistry::class)
            ->execute(PluginDomain::ADDONS, 'auto_refund', 'auto_refund.status');

        $this->assertTrue((bool) $result['success']);
        $this->assertTrue((bool) $result['data']['enabled']);
        $this->assertTrue((bool) $result['data']['configured']);
        $this->assertTrue((bool) $result['data']['upstream_configured']);
        $this->assertTrue((bool) $result['data']['has_api_key']);
        $this->assertTrue((bool) $result['data']['table_ready']);
        $this->assertSame('auto_refund_applications', $result['data']['table']);

        Http::assertNothingSent();
    }

    public function test_api_key_is_encrypted_and_never_returned_by_display_config(): void
    {
        $plugin = $this->installAutoRefundAddon();

        $row = DB::table('integration_plugin_configs')
            ->where('plugin_id', (int) $plugin->id)
            ->first();

        $this->assertNotNull($row);
        // 密钥进 secret_json 加密列，明文不得出现在配置表里
        $this->assertStringNotContainsString(self::TEST_API_KEY, (string) $row->secret_json);
        $this->assertStringNotContainsString(self::TEST_API_KEY, (string) $row->config_json);

        $repository = $this->app->make(PluginConfigRepository::class);
        $display = $repository->displayConfig($plugin->fresh('config') ?? $plugin);

        $this->assertArrayNotHasKey('api_key', $display['config']);
        $this->assertTrue((bool) ($display['has_secret_values']['api_key'] ?? false));

        // 运行时仍然能读到真实密钥（加密存储不等于能不用）
        $this->assertSame(self::TEST_API_KEY, $repository->resolvedConfig($plugin->fresh('config') ?? $plugin)['api_key']);
    }

    public function test_unknown_action_is_reported_without_throwing(): void
    {
        $this->installAutoRefundAddon();

        $result = $this->app->make(PluginRuntimeRegistry::class)
            ->execute(PluginDomain::ADDONS, 'auto_refund', 'auto_refund.not_an_action');

        $this->assertFalse((bool) $result['success']);
        $this->assertStringContainsString('未知的插件动作', (string) $result['message']);
    }

    public function test_runtime_refuses_to_execute_when_the_plugin_is_disabled(): void
    {
        $this->installAutoRefundAddon(enable: false);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('插件未安装或未启用');

        // 记录缓存 60 秒，禁用后要清一下才会重新读状态
        Cache::flush();

        $this->app->make(PluginRuntimeRegistry::class)
            ->execute(PluginDomain::ADDONS, 'auto_refund', 'auto_refund.status');
    }
}
