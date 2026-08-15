<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZjmfProviderKeyMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_converts_persisted_zjmf_identity_references_without_a_compatibility_alias(): void
    {
        $this->assertTrue(Schema::hasTable('integration_plugins'));
        $this->assertTrue(Schema::hasTable('integration_plugin_runtime_logs'));
        $this->assertTrue(Schema::hasTable('settings'));

        $suffix = bin2hex(random_bytes(6));
        $runtimeLogId = DB::table('integration_plugin_runtime_logs')->insertGetId([
            'trace_id' => 'zjmf-migration-'.$suffix,
            'domain' => 'upstream',
            'plugin_id' => null,
            'plugin_key' => 'mofang_finance_api',
            'slug' => 'mofang_finance',
            'action' => 'test',
            'status' => 'success',
            'created_at' => now(),
        ]);
        $settingKey = 'zjmf_identity_migration_'.$suffix;
        DB::table('settings')->insert([
            'group_key' => 'testing',
            'item_key' => $settingKey,
            'item_value' => json_encode([
                'provider_key' => 'mofang_finance_api',
                'hook' => 'plugins.mofang_finance.auth_refresh',
            ], JSON_THROW_ON_ERROR),
        ]);

        $legacyPluginId = $this->insertLegacyPluginWhenNoZjmfPluginExists($suffix);

        $migration = require database_path('_archive/migrations/2026_07_17_000002_rename_zjmf_finance_plugin_identity.php');
        $migration->up();

        $this->assertDatabaseHas('integration_plugin_runtime_logs', [
            'id' => $runtimeLogId,
            'plugin_key' => 'zjmf_finance_api',
            'slug' => 'zjmf_finance',
        ]);
        $this->assertSame(
            '{"provider_key":"zjmf_finance_api","hook":"plugins.zjmf_finance.auth_refresh"}',
            DB::table('settings')->where('group_key', 'testing')->where('item_key', $settingKey)->value('item_value'),
        );

        if ($legacyPluginId !== null) {
            $this->assertDatabaseHas('integration_plugins', [
                'id' => $legacyPluginId,
                'domain' => 'upstream',
                'slug' => 'zjmf_finance',
                'plugin_key' => 'zjmf_finance_api',
                'entry_class' => 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\ZjmfFinancePlugin',
            ]);
        }
    }

    private function insertLegacyPluginWhenNoZjmfPluginExists(string $suffix): ?int
    {
        $existingZjmfPlugin = DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where(fn ($query) => $query
                ->where('slug', 'zjmf_finance')
                ->orWhere('plugin_key', 'zjmf_finance_api'))
            ->exists();

        if ($existingZjmfPlugin) {
            return null;
        }

        $existingLegacyPlugin = DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where(fn ($query) => $query
                ->where('slug', 'mofang_finance')
                ->orWhere('plugin_key', 'mofang_finance_api'))
            ->first(['id']);

        if ($existingLegacyPlugin !== null) {
            return (int) $existingLegacyPlugin->id;
        }

        return DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => 'mofang_finance',
            'plugin_key' => 'mofang_finance_api',
            'name' => 'Legacy '.$suffix,
            'version' => '1.0.0',
            'entry_class' => 'LegacyEntry',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
