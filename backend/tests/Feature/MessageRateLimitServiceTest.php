<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationPlugin;
use App\Models\IntegrationPluginConfig;
use App\Models\Setting;
use App\Services\Auth\MessageRateLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MessageRateLimitServiceTest extends TestCase
{
    public function test_message_limit_counts_single_ip_per_minute_from_active_mail_plugin(): void
    {
        $this->ensurePluginTables();
        $originalRows = $this->snapshotMessageLimitSettings();
        $existingBindings = $this->removeDriverBindings('mail', 'mail_driver');
        $createdPluginIds = [];
        Cache::flush();

        try {
            DB::table('settings')->where('group_key', 'message_limit')->delete();
            Setting::setValues('message_limit', [
                'email_rate_limit_enabled' => '0',
                'email_ip_minute_limit' => '99',
            ]);

            $plugin = $this->createPlugin('mail', 'rate_limit_mail', 'rate_limit_mail', [
                'rate_limit_enabled' => true,
                'ip_minute_limit' => 2,
            ]);
            $createdPluginIds[] = (int) $plugin->id;
            $this->bindPlugin($plugin, 'mail_driver', 'rate_limit_mail');

            $service = app(MessageRateLimitService::class);
            $ip = '203.0.113.10';

            $this->assertTrue($service->check('email', 'first@example.com', $ip)['ok']);
            $service->hit('email', 'first@example.com', $ip);

            $this->assertTrue($service->check('email', 'second@example.com', $ip)['ok']);
            $service->hit('email', 'second@example.com', $ip);

            $blocked = $service->check('email', 'third@example.com', $ip);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('当前 IP 每分钟发送次数已达上限，请稍后再试', $blocked['message']);

            $this->assertTrue($service->check('email', 'first@example.com', '203.0.113.11')['ok']);
        } finally {
            Cache::flush();
            $this->deleteCreatedPlugins($createdPluginIds);
            $this->restoreDriverBindings('mail', 'mail_driver', $existingBindings);
            DB::table('settings')->where('group_key', 'message_limit')->delete();
            if ($originalRows !== []) {
                DB::table('settings')->insert($originalRows);
            }
        }
    }

    public function test_plugin_config_can_disable_message_rate_limit_even_when_old_system_setting_is_enabled(): void
    {
        $this->ensurePluginTables();
        $originalRows = $this->snapshotMessageLimitSettings();
        $existingBindings = $this->removeDriverBindings('mail', 'mail_driver');
        $createdPluginIds = [];
        Cache::flush();

        try {
            DB::table('settings')->where('group_key', 'message_limit')->delete();
            Setting::setValues('message_limit', [
                'email_rate_limit_enabled' => '1',
                'email_ip_minute_limit' => '1',
            ]);

            $plugin = $this->createPlugin('mail', 'rate_limit_disabled_mail', 'rate_limit_disabled_mail', [
                'rate_limit_enabled' => false,
                'ip_minute_limit' => 1,
            ]);
            $createdPluginIds[] = (int) $plugin->id;
            $this->bindPlugin($plugin, 'mail_driver', 'rate_limit_disabled_mail');

            $service = app(MessageRateLimitService::class);
            $ip = '203.0.113.20';

            $this->assertTrue($service->check('email', 'first@example.com', $ip)['ok']);
            $service->hit('email', 'first@example.com', $ip);

            $this->assertTrue($service->check('email', 'second@example.com', $ip)['ok']);
            $service->hit('email', 'second@example.com', $ip);

            $this->assertTrue($service->check('email', 'third@example.com', $ip)['ok']);
        } finally {
            Cache::flush();
            $this->deleteCreatedPlugins($createdPluginIds);
            $this->restoreDriverBindings('mail', 'mail_driver', $existingBindings);
            DB::table('settings')->where('group_key', 'message_limit')->delete();
            if ($originalRows !== []) {
                DB::table('settings')->insert($originalRows);
            }
        }
    }

    public function test_message_limit_blocks_same_target_across_rotating_ips(): void
    {
        $this->ensurePluginTables();
        $originalRows = $this->snapshotMessageLimitSettings();
        $existingBindings = $this->removeDriverBindings('sms', 'sms_driver');
        $createdPluginIds = [];
        Cache::flush();

        try {
            DB::table('settings')->where('group_key', 'message_limit')->delete();

            $plugin = $this->createPlugin('sms', 'rate_limit_target_sms', 'rate_limit_target_sms', [
                'rate_limit_enabled' => true,
                'ip_minute_limit' => 2,
                'target_hour_limit' => 3,
                'target_day_limit' => 10,
            ]);
            $createdPluginIds[] = (int) $plugin->id;
            $this->bindPlugin($plugin, 'sms_driver', 'rate_limit_target_sms');

            $service = app(MessageRateLimitService::class);
            $target = '13800001111';

            // 轮换 IP 对同一目标发送，IP 维度各自未超限，但 target 维度达到上限
            foreach (['203.0.113.31', '203.0.113.32', '203.0.113.33'] as $ip) {
                $this->assertTrue($service->check('sms', $target, $ip)['ok']);
                $service->hit('sms', $target, $ip);
            }

            $blocked = $service->check('sms', $target, '203.0.113.99');
            $this->assertFalse($blocked['ok']);
            $this->assertSame('该接收方一小时内发送次数已达上限，请稍后再试', $blocked['message']);

            // 其他目标不受影响
            $this->assertTrue($service->check('sms', '13800002222', '203.0.113.99')['ok']);
        } finally {
            Cache::flush();
            $this->deleteCreatedPlugins($createdPluginIds);
            $this->restoreDriverBindings('sms', 'sms_driver', $existingBindings);
            DB::table('settings')->where('group_key', 'message_limit')->delete();
            if ($originalRows !== []) {
                DB::table('settings')->insert($originalRows);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createPlugin(string $domain, string $slug, string $key, array $config): IntegrationPlugin
    {
        $plugin = IntegrationPlugin::query()->updateOrCreate(
            [
                'domain' => $domain,
                'slug' => $slug,
            ],
            [
                'plugin_key' => $key,
                'name' => 'Rate Limit Test '.$slug,
                'version' => '1.0.0',
                'entry_class' => self::class,
                'capabilities_json' => [],
                'config_schema_json' => [],
                'status' => IntegrationPlugin::STATUS_ENABLED,
                'installed_at' => now(),
            ]
        );

        IntegrationPluginConfig::query()->updateOrCreate(
            ['plugin_id' => (int) $plugin->id],
            [
                'config_json' => $config,
                'secret_json' => null,
                'has_secret_json' => [],
            ]
        );

        return $plugin;
    }

    private function bindPlugin(IntegrationPlugin $plugin, string $bindingKey, string $providerKey): void
    {
        DB::table('integration_plugin_bindings')->updateOrInsert(
            [
                'domain' => (string) $plugin->domain,
                'binding_type' => 'global',
                'bindable_type' => 'setting',
                'bindable_id' => 0,
                'binding_key' => $bindingKey,
            ],
            [
                'plugin_id' => (int) $plugin->id,
                'provider_key' => $providerKey,
                'priority' => 0,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function removeDriverBindings(string $domain, string $bindingKey): array
    {
        $bindings = DB::table('integration_plugin_bindings')
            ->where('domain', $domain)
            ->where('binding_key', $bindingKey)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        DB::table('integration_plugin_bindings')
            ->where('domain', $domain)
            ->where('binding_key', $bindingKey)
            ->delete();

        return array_values($bindings);
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     */
    private function restoreDriverBindings(string $domain, string $bindingKey, array $bindings): void
    {
        DB::table('integration_plugin_bindings')
            ->where('domain', $domain)
            ->where('binding_key', $bindingKey)
            ->delete();

        if ($bindings !== []) {
            DB::table('integration_plugin_bindings')->insert($bindings);
        }
    }

    /**
     * @param  list<int>  $pluginIds
     */
    private function deleteCreatedPlugins(array $pluginIds): void
    {
        $pluginIds = array_values(array_filter($pluginIds, static fn (int $id): bool => $id > 0));
        if ($pluginIds === []) {
            return;
        }

        DB::table('integration_plugin_bindings')->whereIn('plugin_id', $pluginIds)->delete();
        IntegrationPluginConfig::query()->whereIn('plugin_id', $pluginIds)->delete();
        IntegrationPlugin::query()
            ->whereIn('id', $pluginIds)
            ->update(['status' => IntegrationPlugin::STATUS_DISABLED]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotMessageLimitSettings(): array
    {
        return DB::table('settings')
            ->where('group_key', 'message_limit')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
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
}
