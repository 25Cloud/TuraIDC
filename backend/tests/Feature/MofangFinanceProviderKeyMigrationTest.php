<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\IntegrationPlugin;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\MofangFinanceProviderKeyMigrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MofangFinanceProviderKeyMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_always_emits_a_json_dry_run_report(): void
    {
        $this->plugin(MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY, 'mofang_finance');
        $this->plugin(MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY, 'zjmf_finance');

        $exitCode = Artisan::call('db:rename-mofang-finance-provider', ['--dry-run' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            $payload['report']['from'],
        );
    }

    public function test_it_only_moves_whitelisted_live_routes_and_top_level_json_keys(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $legacyPlugin = $this->plugin(MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY, 'mofang_finance');
        $targetPlugin = $this->plugin(MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY, 'zjmf_finance');
        $targetStatus = (int) $targetPlugin->status;
        $legacyStatus = (int) $legacyPlugin->status;

        $user = User::query()->create([
            'email' => 'provider-cutover-'.$suffix.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Provider cutover '.$suffix,
            'code' => 'cutover-'.$suffix,
            'status' => 1,
        ]);
        $product = Product::query()->create([
            'custom_display_name' => 'Provider cutover '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Provider cutover service '.$suffix,
            'domain' => 'provider-cutover-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => $this->payloadWithLegacyProvider(),
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
        $unroutedService = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Unrouted provider snapshot '.$suffix,
            'domain' => 'unrouted-provider-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => $this->payloadWithLegacyProvider(),
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
        $now = now();
        $integrationBindingId = DB::table('integration_plugin_bindings')->insertGetId([
            'domain' => 'upstream',
            'plugin_id' => (int) $legacyPlugin->id,
            'binding_type' => 'migration-test',
            'bindable_type' => 'migration-test-'.$suffix,
            'bindable_id' => random_int(100000, 999999),
            'binding_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'config_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'runtime_policy_json' => json_encode(['provider' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'status' => 1,
            'priority' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'environment' => 'test-'.$suffix,
            'status' => 1,
            'priority' => 0,
            'config_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productBindingId = DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'upstream_product_id' => 'provider-cutover-'.$suffix,
            'upstream_product_snapshot_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serviceBindingId = DB::table('service_upstream_bindings')->insertGetId([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'upstream_service_id' => 'provider-cutover-'.$suffix,
            'runtime_snapshot_json' => json_encode($this->payloadWithLegacyProvider(), JSON_THROW_ON_ERROR),
            'connection_snapshot_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runtimeSnapshotId = DB::table('service_runtime_snapshots')->insertGetId([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $serviceBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'snapshot_json' => json_encode($this->payloadWithLegacyProvider(), JSON_THROW_ON_ERROR),
            'resource_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionSnapshotId = DB::table('service_connection_snapshots')->insertGetId([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $serviceBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'connection_type' => 'migration-test-'.$suffix,
            'connection_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'secret_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $attemptId = DB::table('service_provision_attempts')->insertGetId([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $serviceBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'action' => 'migration-test',
            'attempt_status' => 'success',
            'request_meta_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'attempted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runtimeLogId = DB::table('integration_plugin_runtime_logs')->insertGetId([
            'trace_id' => 'provider-cutover-'.$suffix,
            'domain' => 'upstream',
            'plugin_id' => (int) $legacyPlugin->id,
            'plugin_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'slug' => 'mofang_finance',
            'action' => 'migration-test',
            'status' => 'success',
            'request_meta_json' => json_encode(['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        $migration = app(MofangFinanceProviderKeyMigrationService::class);
        $dryRun = $migration->inspect();

        $this->assertSame('dry_run', $dryRun['mode']);
        $this->assertGreaterThanOrEqual(1, $dryRun['routing']['service_upstream_bindings']['candidate_rows']);
        $this->assertGreaterThanOrEqual(1, $dryRun['json']['services.provision_data']['skipped_unrouted_rows']);
        $this->assertDatabaseHas('service_upstream_bindings', [
            'id' => $serviceBindingId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
        ]);

        $report = $migration->execute();

        $this->assertSame('execute', $report['mode']);
        $this->assertTrue((bool) $report['plugins']['legacy_record_retained']);
        $this->assertSame(0, $report['remaining_legacy_references']['total']);
        $this->assertSame($legacyStatus, (int) $legacyPlugin->fresh()->status);
        $this->assertSame($targetStatus, (int) $targetPlugin->fresh()->status);
        $this->assertDatabaseHas('integration_plugins', ['id' => (int) $legacyPlugin->id]);

        foreach ([
            ['integration_plugin_bindings', $integrationBindingId],
            ['supplier_plugin_bindings', $supplierBindingId],
            ['product_upstream_bindings', $productBindingId],
            ['service_upstream_bindings', $serviceBindingId],
            ['service_runtime_snapshots', $runtimeSnapshotId],
            ['service_connection_snapshots', $connectionSnapshotId],
        ] as [$table, $id]) {
            $this->assertDatabaseHas($table, [
                'id' => $id,
                'plugin_id' => (int) $targetPlugin->id,
                'provider_key' => MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY,
            ]);
        }
        $this->assertDatabaseHas('integration_plugin_bindings', [
            'id' => $integrationBindingId,
            'binding_key' => MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY,
        ]);

        $this->assertWhitelistedPayloadWasChanged(
            json_decode((string) DB::table('services')->where('id', $service->id)->value('provision_data'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertWhitelistedPayloadWasChanged(
            json_decode((string) DB::table('service_upstream_bindings')->where('id', $serviceBindingId)->value('runtime_snapshot_json'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertWhitelistedPayloadWasChanged(
            json_decode((string) DB::table('service_runtime_snapshots')->where('id', $runtimeSnapshotId)->value('snapshot_json'), true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('integration_plugin_bindings')->where('id', $integrationBindingId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('supplier_plugin_bindings')->where('id', $supplierBindingId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('product_upstream_bindings')->where('id', $productBindingId)->value('upstream_product_snapshot_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('service_upstream_bindings')->where('id', $serviceBindingId)->value('connection_snapshot_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('service_runtime_snapshots')->where('id', $runtimeSnapshotId)->value('resource_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('service_connection_snapshots')->where('id', $connectionSnapshotId)->value('connection_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('service_connection_snapshots')->where('id', $connectionSnapshotId)->value('secret_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('services')->where('id', $unroutedService->id)->value('provision_data'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );

        $this->assertDatabaseHas('service_provision_attempts', [
            'id' => $attemptId,
            'plugin_id' => (int) $legacyPlugin->id,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
        ]);
        $this->assertDatabaseHas('integration_plugin_runtime_logs', [
            'id' => $runtimeLogId,
            'plugin_id' => (int) $legacyPlugin->id,
            'plugin_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
        ]);
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('service_provision_attempts')->where('id', $attemptId)->value('request_meta_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
        $this->assertSame(
            MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            json_decode((string) DB::table('integration_plugin_runtime_logs')->where('id', $runtimeLogId)->value('request_meta_json'), true, 512, JSON_THROW_ON_ERROR)['provider_key'],
        );
    }

    private function plugin(string $key, string $slug): IntegrationPlugin
    {
        $plugin = IntegrationPlugin::query()
            ->where('domain', 'upstream')
            ->where('plugin_key', $key)
            ->first();

        if ($plugin instanceof IntegrationPlugin) {
            return $plugin;
        }

        return IntegrationPlugin::query()->create([
            'domain' => 'upstream',
            'slug' => $slug,
            'plugin_key' => $key,
            'name' => $slug,
            'version' => 'test',
            'entry_class' => 'Tests\\Feature\\'.$slug,
            'status' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadWithLegacyProvider(): array
    {
        return [
            'provider' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'original_provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY,
            'nested' => ['provider_key' => MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertWhitelistedPayloadWasChanged(array $payload): void
    {
        $this->assertSame(MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY, $payload['provider']);
        $this->assertSame(MofangFinanceProviderKeyMigrationService::TARGET_PROVIDER_KEY, $payload['provider_key']);
        $this->assertSame(MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY, $payload['original_provider_key']);
        $this->assertSame(MofangFinanceProviderKeyMigrationService::LEGACY_PROVIDER_KEY, $payload['nested']['provider_key']);
    }
}
