<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminIntegrationPluginControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPluginTables();
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    public function test_admin_can_scan_install_configure_and_enable_plugin(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $listResponse = $this->getJson('/api/v2/admin/integration-plugins')
            ->assertOk()
            ->assertJsonPath('data.total', fn (int $total): bool => $total >= 6)
            ->assertJsonPath('data.list.0.domain', fn (string $domain): bool => in_array($domain, ['addons', 'captcha', 'mail', 'payment', 'sms', 'upstream', 'verification'], true));

        $this->assertFalse(collect($listResponse->json('data.list'))->contains(
            fn (array $plugin): bool => str_starts_with((string) ($plugin['slug'] ?? ''), 'demo_')
        ));
        $this->assertTrue(collect($listResponse->json('data.list'))->contains(
            fn (array $plugin): bool => ($plugin['slug'] ?? '') === 'stay33'
        ));

        $installResponse = $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'verification',
            'slug' => 'stay33',
        ])->assertOk();

        $pluginId = (int) $installResponse->json('data.plugin.id');
        $this->assertGreaterThan(0, $pluginId);
        $schema = $this->getJson("/api/v2/admin/integration-plugins/{$pluginId}/schema")
            ->assertOk()
            ->assertJsonPath('data.schema.0.type', 'notice')
            ->assertJsonPath('data.schema.1.placeholder', '请输入 API 标识')
            ->assertJsonPath('data.schema.2.type', 'password')
            ->json('data.schema');
        $this->assertTrue(collect($schema)->contains(
            fn (array $field): bool => ($field['key'] ?? '') === 'amount'
                && ($field['visible_when']['field'] ?? null) === 'charge_enabled'
        ));

        $this->putJson("/api/v2/admin/integration-plugins/{$pluginId}/config", [
            'config' => [
                'api' => 'stay33-api',
                'key' => 'stay33-secret',
                'biz_code' => 'FACE',
                'charge_enabled' => true,
                'amount' => '2.00',
                'free_times' => 1,
            ],
        ])->assertOk()
            ->assertJsonPath('data.plugin.config.api', 'stay33-api')
            ->assertJsonMissingPath('data.plugin.config.basic_notice')
            ->assertJsonPath('data.plugin.configured_credentials.key', true);

        $this->patchJson("/api/v2/admin/integration-plugins/{$pluginId}/status", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled')
            ->assertJsonPath('data.detail.plugin.is_enabled', true);

        $this->getJson("/api/v2/admin/integration-plugins/{$pluginId}")
            ->assertOk()
            ->assertJsonPath('data.plugin.domain', 'verification')
            ->assertJsonPath('data.plugin.slug', 'stay33')
            ->assertJsonPath('data.plugin.is_enabled', true)
            ->assertJsonMissingPath('data.plugin.config.key');

        $this->assertDatabaseHas('integration_plugin_bindings', [
            'domain' => 'verification',
            'plugin_id' => $pluginId,
            'binding_key' => 'verification_driver',
            'provider_key' => 'stay33',
            'status' => 1,
        ]);

        $verificationList = $this->getJson('/api/v2/admin/integration-plugins?domain=verification')
            ->assertOk()
            ->json('data.list');

        $this->assertNull(collect($verificationList)->firstWhere('slug', 'demo_verification'));

        $stay33 = collect($verificationList)->firstWhere('slug', 'stay33');
        $this->assertIsArray($stay33);
        $this->assertTrue((bool) ($stay33['is_installed'] ?? false));
        $this->assertTrue((bool) ($stay33['is_enabled'] ?? false));
    }

    public function test_admin_can_configure_and_enable_captcha_plugin(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $installResponse = $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'captcha',
            'slug' => 'geetest',
        ])->assertOk();

        $pluginId = (int) $installResponse->json('data.plugin.id');
        $this->assertGreaterThan(0, $pluginId);

        $this->putJson("/api/v2/admin/integration-plugins/{$pluginId}/config", [
            'config' => [
                'captcha_id' => 'captcha-id',
                'captcha_key' => 'captcha-secret',
            ],
        ])->assertOk()
            ->assertJsonPath('data.plugin.config.captcha_id', 'captcha-id')
            ->assertJsonMissingPath('data.plugin.config.captcha_key')
            ->assertJsonPath('data.plugin.configured_credentials.captcha_key', true);

        $this->getJson("/api/v2/admin/integration-plugins/{$pluginId}/secrets/captcha_key")
            ->assertOk()
            ->assertJsonPath('data.key', 'captcha_key')
            ->assertJsonPath('data.value', 'captcha-secret');

        $this->patchJson("/api/v2/admin/integration-plugins/{$pluginId}/status", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled')
            ->assertJsonPath('data.detail.plugin.is_enabled', true);

        $this->assertDatabaseHas('integration_plugin_bindings', [
            'domain' => 'captcha',
            'plugin_id' => $pluginId,
            'binding_key' => 'captcha_driver',
            'provider_key' => 'geetest',
            'status' => 1,
        ]);
    }

    public function test_single_enabled_domain_blocks_enabling_another_plugin(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $smtpResponse = $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'mail',
            'slug' => 'smtp',
        ])->assertOk();
        $smtpId = (int) $smtpResponse->json('data.plugin.id');

        $this->patchJson("/api/v2/admin/integration-plugins/{$smtpId}/status", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'enabled')
            ->assertJsonPath('data.detail.plugin.is_enabled', true);

        $multiSmtpResponse = $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'mail',
            'slug' => 'multi_smtp_round_robin',
        ])->assertOk();
        $multiSmtpId = (int) $multiSmtpResponse->json('data.plugin.id');

        $this->putJson("/api/v2/admin/integration-plugins/{$multiSmtpId}/config", [
            'config' => [
                'accounts' => [[
                    'host' => 'smtp.example.test',
                    'port' => 465,
                    'username' => 'notice@example.test',
                    'password' => 'secret',
                    'from_name' => '图拉云',
                    'encryption' => 'ssl',
                    'enabled' => true,
                ]],
            ],
        ])->assertOk();

        $mailList = collect($this->getJson('/api/v2/admin/integration-plugins?domain=mail')
            ->assertOk()
            ->json('data.list'));
        $multiSmtp = $mailList->firstWhere('slug', 'multi_smtp_round_robin');

        $this->assertIsArray($multiSmtp);
        $this->assertFalse((bool) ($multiSmtp['can_enable'] ?? true));
        $this->assertStringContainsString('Single SMTP', (string) ($multiSmtp['enable_disabled_reason'] ?? ''));

        $this->patchJson("/api/v2/admin/integration-plugins/{$multiSmtpId}/status", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Single SMTP'));

        $this->assertDatabaseHas('integration_plugins', [
            'id' => $multiSmtpId,
            'status' => IntegrationPlugin::STATUS_DISABLED,
        ]);
    }

    public function test_install_does_not_hydrate_captcha_plugin_from_old_settings(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());
        $originalCaptchaId = Setting::getValue('system', 'geetest_captcha_id', '');
        $originalCaptchaKey = Setting::getValue('system', 'geetest_captcha_key', '');

        Setting::setValues('system', [
            'geetest_captcha_id' => 'legacy-captcha-id',
            'geetest_captcha_key' => 'legacy-captcha-secret',
        ]);

        try {
            $installResponse = $this->postJson('/api/v2/admin/integration-plugins', [
                'domain' => 'captcha',
                'slug' => 'geetest',
            ])->assertOk()
                ->assertJsonPath('data.plugin.config', [])
                ->assertJsonPath('data.plugin.configured_credentials', []);

            $pluginId = (int) $installResponse->json('data.plugin.id');
            $this->assertGreaterThan(0, $pluginId);

            $this->patchJson("/api/v2/admin/integration-plugins/{$pluginId}/status", ['enabled' => true])
                ->assertStatus(422)
                ->assertJsonPath('code', 42200);
        } finally {
            Setting::setValues('system', [
                'geetest_captcha_id' => $originalCaptchaId,
                'geetest_captcha_key' => $originalCaptchaKey,
            ]);
        }
    }

    public function test_test_email_rejects_invalid_email_with_unified_validation_error(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());
        $plugin = $this->createPlugin('mail', 'smtp');

        $this->postJson("/api/v2/admin/integration-plugins/{$plugin->id}/tasks", [
            'type' => 'test_email',
            'payload' => [
                'account_index' => 0,
                'to' => 'not-an-email',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '参数验证失败')
            ->assertJsonStructure(['data' => ['errors' => ['payload.to']]]);
    }

    public function test_test_sms_rejects_invalid_payload_with_unified_validation_error(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());
        $plugin = $this->createPlugin('sms', 'aliyun_sms');

        $this->postJson("/api/v2/admin/integration-plugins/{$plugin->id}/tasks", [
            'type' => 'test_sms',
            'payload' => [
                'phone' => str_repeat('1', 21),
            ],
        ])->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '参数验证失败')
            ->assertJsonStructure(['data' => ['errors' => ['payload.phone']]]);
    }

    public function test_admin_can_remove_plugin_registry_and_config_when_payment_history_references_plugin(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $plugin = $this->createPlugin('upstream', 'zjmf_finance');
        DB::table('integration_plugin_configs')->insert([
            'plugin_id' => (int) $plugin->id,
            'config_json' => json_encode(['api_url' => 'https://zjmf.example.test']),
            'secret_json' => null,
            'has_secret_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'email' => 'plugin-delete-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PAYPLUGIN'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'plugin_id' => (int) $plugin->id,
            'gateway' => 'alipay',
            'trade_no' => 'ALI'.strtoupper(bin2hex(random_bytes(4))),
            'amount' => '1.00',
            'status' => 1,
            'paid_at' => now(),
        ]);

        // 存在支付历史时普通卸载会被拒绝，必须由管理端二次确认后强制卸载。
        $this->deleteJson("/api/v2/admin/integration-plugins/{$plugin->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('integration_plugins', ['id' => $plugin->id]);

        $this->deleteJson("/api/v2/admin/integration-plugins/{$plugin->id}?force=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.detail.deleted', true);

        $this->assertDatabaseMissing('integration_plugins', ['id' => $plugin->id]);
        $this->assertDatabaseMissing('integration_plugin_configs', ['plugin_id' => $plugin->id]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'plugin_id' => null,
        ]);
        $this->assertDirectoryExists(base_path('plugins/servers/zjmf_finance'));
    }

    public function test_installed_demo_plugin_is_not_reported_as_missing_manifest(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        // demo 插件文件真实存在，安装记录也在，列表不能把它当成“文件已丢失”。
        app(PluginInstaller::class)->install('payment', 'demo_pay');

        $list = $this->getJson('/api/v2/admin/integration-plugins?domain=payment')
            ->assertOk()
            ->json('data.list');

        $this->assertNotContains(
            true,
            array_map(fn (array $item): bool => (bool) ($item['manifest_missing'] ?? false), $list),
            'demo 插件已安装且文件存在，不应出现 manifest_missing 条目'
        );
        $this->assertNull(collect($list)->firstWhere('slug', 'demo_pay'));
    }

    public function test_admin_cannot_install_demo_plugin(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $this->postJson('/api/v2/admin/integration-plugins', [
            'domain' => 'payment',
            'slug' => 'demo_pay',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('integration_plugins', [
            'domain' => 'payment',
            'slug' => 'demo_pay',
        ]);
    }

    public function test_uninstall_without_force_is_rejected_when_plugin_is_still_bound(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $plugin = $this->createPlugin('upstream', 'bound_plugin_'.bin2hex(random_bytes(4)));
        DB::table('integration_plugin_bindings')->insert([
            'domain' => 'upstream',
            'plugin_id' => (int) $plugin->id,
            'binding_type' => 'global',
            'bindable_type' => 'setting',
            'bindable_id' => 0,
            'binding_key' => 'force-uninstall-test',
            'provider_key' => 'force-uninstall-test',
            'priority' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v2/admin/integration-plugins/{$plugin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '插件绑定'));

        $this->assertDatabaseHas('integration_plugins', ['id' => $plugin->id]);
        $this->assertDatabaseHas('integration_plugin_bindings', ['plugin_id' => $plugin->id]);

        $this->deleteJson("/api/v2/admin/integration-plugins/{$plugin->id}?force=1")
            ->assertOk()
            ->assertJsonPath('data.detail.deleted', true);

        $this->assertDatabaseMissing('integration_plugins', ['id' => $plugin->id]);
        $this->assertDatabaseMissing('integration_plugin_bindings', ['plugin_id' => $plugin->id]);
    }

    public function test_admin_can_disable_and_remove_enabled_plugin_when_manifest_is_missing(): void
    {
        $this->ensurePluginTables();
        Sanctum::actingAs($this->createAdmin());

        $plugin = $this->createPlugin('upstream', 'missing_manifest_'.bin2hex(random_bytes(4)));
        DB::table('integration_plugin_configs')->insert([
            'plugin_id' => (int) $plugin->id,
            'config_json' => json_encode(['api_url' => 'https://missing.example.test']),
            'secret_json' => null,
            'has_secret_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('integration_plugin_bindings')->insert([
            'domain' => 'upstream',
            'plugin_id' => (int) $plugin->id,
            'binding_type' => 'global',
            'bindable_type' => 'setting',
            'bindable_id' => 0,
            'binding_key' => 'missing-manifest-test',
            'provider_key' => 'missing-manifest-test',
            'priority' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'email' => 'missing-manifest-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'PAYMISSING'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'plugin_id' => (int) $plugin->id,
            'gateway' => 'alipay',
            'trade_no' => 'ALI'.strtoupper(bin2hex(random_bytes(4))),
            'amount' => '1.00',
            'status' => 1,
            'paid_at' => now(),
        ]);

        $this->patchJson("/api/v2/admin/integration-plugins/{$plugin->id}/status", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertDatabaseHas('integration_plugins', [
            'id' => $plugin->id,
            'status' => IntegrationPlugin::STATUS_DISABLED,
        ]);
        $this->assertDatabaseHas('integration_plugin_bindings', [
            'plugin_id' => $plugin->id,
            'status' => 0,
        ]);

        $this->deleteJson("/api/v2/admin/integration-plugins/{$plugin->id}?force=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.detail.deleted', true);

        $this->assertDatabaseMissing('integration_plugins', ['id' => $plugin->id]);
        $this->assertDatabaseMissing('integration_plugin_configs', ['plugin_id' => $plugin->id]);
        $this->assertDatabaseMissing('integration_plugin_bindings', ['plugin_id' => $plugin->id]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'plugin_id' => null,
        ]);
    }

    private function createAdmin(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'plugin-admin-'.$suffix,
            'label' => 'Plugin Admin',
            'permissions' => [
                AdminPermissions::INTEGRATION_PLUGIN_MANAGE,
                AdminPermissions::INTEGRATION_PLUGIN_TEST,
                AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL,
            ],
        ]);

        return AdminUser::query()->create([
            'username' => 'plugin-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Plugin Admin',
            'email' => 'plugin-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createPlugin(string $domain, string $slug): IntegrationPlugin
    {
        return IntegrationPlugin::query()->create([
            'domain' => $domain,
            'slug' => $slug,
            'plugin_key' => $slug,
            'name' => 'Test '.$slug,
            'version' => '1.0.0',
            'entry_class' => 'Tests\\Fixtures\\Plugin',
            'capabilities_json' => [],
            'config_schema_json' => [],
            'status' => IntegrationPlugin::STATUS_ENABLED,
            'installed_at' => now(),
        ]);
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }

        if (! Schema::hasTable('integration_plugin_bindings')) {
            Schema::create('integration_plugin_bindings', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->unsignedBigInteger('plugin_id');
                $table->string('binding_type', 50);
                $table->string('bindable_type', 120)->default('global');
                $table->unsignedBigInteger('bindable_id')->default(0);
                $table->string('binding_key', 120);
                $table->string('provider_key', 120)->nullable();
                $table->integer('priority')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->json('runtime_policy_json')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('backfill_batch_id', 64)->nullable();
                $table->timestamps();
                $table->unique(['domain', 'binding_type', 'bindable_type', 'bindable_id', 'binding_key'], 'plugin_bindings_unique');
            });
        }
    }

    private function cleanPluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        if (Schema::hasTable('integration_plugin_bindings')) {
            DB::table('integration_plugin_bindings')->truncate();
        }
        if (Schema::hasTable('integration_plugin_configs')) {
            DB::table('integration_plugin_configs')->truncate();
        }
        DB::table('integration_plugins')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
