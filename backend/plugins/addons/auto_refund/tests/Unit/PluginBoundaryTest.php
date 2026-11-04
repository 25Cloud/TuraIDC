<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 边界守卫（不启动 Laravel、不连库）。
 *
 * 插件规范第 9 节：插件不能自行注册管理端菜单、权限、迁移和任意路由；
 * 反过来核心也不该知道插件存在——除了 `OptionalFeature` 那一行登记。
 * 这里把两条都钉住，防止后续把 AutoRefund 的类漏进主应用。
 */
class PluginBoundaryTest extends TestCase
{
    public function test_runtime_is_fully_owned_by_the_addon(): void
    {
        $backendPath = dirname(__DIR__, 5);

        $this->assertDirectoryExists($backendPath.'/plugins/addons/auto_refund/src');
        $this->assertFileDoesNotExist($backendPath.'/app/Services/AutoRefundService.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Services/AutoRefund/AutoRefundService.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Models/AutoRefundApplication.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Controllers/Client/AutoRefundController.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Controllers/Admin/AutoRefundAdminController.php');
        $this->assertFileDoesNotExist($backendPath.'/routes/auto-refund.php');
        $this->assertFileDoesNotExist($backendPath.'/config/auto_refund.php');

        // 迁移正好相反：插件不能自己注册迁移（插件标准 v2 §3），所以建表与登记
        // 必须落在核心迁移里——与 addons/ai_assistant 同一口径。插件目录下不允许
        // 出现自己的 database/migrations，否则那段 DDL 永远不会被执行。
        $this->assertDirectoryDoesNotExist($backendPath.'/plugins/addons/auto_refund/database');
        $this->assertFileExists($backendPath.'/database/migrations/2026_11_01_000001_create_auto_refund_applications_table.php');
        $this->assertFileExists($backendPath.'/database/migrations/2026_11_01_000002_register_auto_refund_plugin.php');
        $this->assertFileExists($backendPath.'/database/migrations/2026_11_03_000001_create_auto_refund_product_rules_table.php');
        $this->assertFileExists($backendPath.'/database/migrations/2026_11_03_000002_add_channel_and_ticket_to_auto_refund_applications.php');
    }

    public function test_provider_only_registers_its_own_routes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Providers/AutoRefundServiceProvider.php');

        $this->assertStringContainsString("prefix('api/v2/client')", $source);
        $this->assertStringContainsString("prefix('api/v2/admin')", $source);
        $this->assertStringContainsString("'ensure.client'", $source);
        $this->assertStringContainsString("'ensure.admin'", $source);

        // 插件不得注册迁移、系统级调度或全局中间件
        $this->assertStringNotContainsString('loadMigrationsFrom', $source);
        $this->assertStringNotContainsString('Schedule::', $source);
        $this->assertStringNotContainsString('pushMiddleware', $source);
        $this->assertStringNotContainsString('appendMiddlewareToGroup', $source);
    }

    public function test_optional_feature_registers_the_plugin_key(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 5).'/app/Support/OptionalFeature.php');

        $this->assertStringContainsString("public const AUTO_REFUND = 'auto_refund';", $source);
        $this->assertStringContainsString(
            "self::AUTO_REFUND => self::pluginEnabled(PluginDomain::ADDONS, 'auto_refund')",
            $source,
        );
    }

    public function test_manifest_declares_the_contract_metadata_and_config_schema(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2).'/config.php';

        $this->assertSame('addons', $config['info']['domain']);
        $this->assertSame('auto_refund', $config['info']['slug']);
        $this->assertSame('auto_refund', $config['info']['key']);
        $this->assertSame('自助退款', $config['info']['name']);
        $this->assertSame('1.1.0', $config['info']['version']);
        $this->assertArrayHasKey('entry', $config['info']);
        $this->assertArrayHasKey('provider', $config['info']);
        $this->assertNotEmpty($config['info']['capabilities']);

        $fields = array_filter(
            $config['config'],
            static fn (array $item): bool => ! in_array((string) ($item['type'] ?? ''), ['notice', 'readonly', 'divider'], true),
        );

        $expected = [
            'enabled',
            'api_base_url',
            'api_key',
            'request_timeout',
            'console_notice',
            'settle_remark',
            'upstream_precheck',
            'ticket_protocol',
            'ticket_hostname',
            'ticket_username',
            'ticket_api_key',
            'ticket_department_id',
            'ticket_title_template',
            'ticket_content_template',
        ];

        $this->assertSame($expected, array_keys($fields));

        // 契约硬要求：两张出站通道的密钥都用 password + secret，地址默认指向上游 AutoRefund api.php
        $this->assertSame('password', $fields['api_key']['type']);
        $this->assertTrue((bool) $fields['api_key']['secret']);
        $this->assertSame('password', $fields['ticket_api_key']['type']);
        $this->assertTrue((bool) $fields['ticket_api_key']['secret']);
        $this->assertSame(
            'https://www.zixiyun.cn/plugins/addons/auto_refund/api.php',
            $fields['api_base_url']['value'],
        );
        $this->assertSame('select', $fields['ticket_protocol']['type']);
        $this->assertSame('zjfm', $fields['ticket_protocol']['value']);
        $this->assertSame('textarea', $fields['ticket_content_template']['type']);
        $this->assertSame('switch', $fields['upstream_precheck']['type']);
        $this->assertTrue((bool) ($fields['upstream_precheck']['value'] ?? false));

        // 商品策略是逐商品的配置，全局 schema 里不该再有 policy_mode / auto_settle 之类的旧口径
        foreach (['policy_mode', 'policy_days', 'require_first_only', 'min_amount', 'auto_settle'] as $removed) {
            $this->assertArrayNotHasKey($removed, $fields);
        }
    }
}
