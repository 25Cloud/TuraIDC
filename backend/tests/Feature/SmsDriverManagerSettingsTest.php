<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use App\Models\Setting;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\Data\SmsSendResult;
use App\Services\Sms\SmsDriverManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmsDriverManagerSettingsTest extends TestCase
{
    public function test_sms_manager_ignores_legacy_provider_setting_without_plugin_binding(): void
    {
        $this->ensureBindingTable();
        $driver = new FeatureFakeSmsDriver('fake_sms_provider');
        $manager = new SmsDriverManager([$driver]);
        $originalProvider = Setting::getValue('notification', 'sms_provider', '');
        $existingBindings = $this->removeSmsDriverBindings();

        try {
            Setting::setValue('notification', 'sms_provider', 'fake_sms_provider');

            $this->expectException(BusinessException::class);
            $manager->resolve();
        } finally {
            Setting::setValue('notification', 'sms_provider', $originalProvider);
            $this->restoreSmsDriverBindings($existingBindings);
        }
    }

    public function test_sms_manager_uses_plugin_binding_for_default_driver(): void
    {
        $this->ensureBindingTable();
        $existingBindings = $this->removeSmsDriverBindings();
        $plugin = $this->ensureFakeSmsPlugin();

        DB::table('integration_plugin_bindings')->updateOrInsert(
            [
                'domain' => 'sms',
                'binding_type' => 'global',
                'bindable_type' => 'setting',
                'bindable_id' => 0,
                'binding_key' => 'sms_driver',
            ],
            [
                'plugin_id' => (int) $plugin->id,
                'provider_key' => 'fake_sms_provider',
                'priority' => 0,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        try {
            $driver = new FeatureFakeSmsDriver('fake_sms_provider');
            $manager = new SmsDriverManager([$driver]);

            $this->assertSame($driver, $manager->resolve());
        } finally {
            DB::table('integration_plugin_bindings')->where('domain', 'sms')->where('binding_key', 'sms_driver')->delete();
            $this->deleteFakeSmsPlugin();
            $this->restoreSmsDriverBindings($existingBindings);
        }
    }

    private function ensureFakeSmsPlugin(): IntegrationPlugin
    {
        return IntegrationPlugin::query()->updateOrCreate(
            [
                'domain' => 'sms',
                'slug' => 'fake_sms_provider',
            ],
            [
                'plugin_key' => 'fake_sms_provider',
                'name' => 'Fake SMS Provider',
                'version' => '1.0.0',
                'entry_class' => FeatureFakeSmsDriver::class,
                'capabilities_json' => [],
                'config_schema_json' => [],
                'status' => IntegrationPlugin::STATUS_ENABLED,
                'installed_at' => now(),
            ]
        );
    }

    private function deleteFakeSmsPlugin(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            IntegrationPlugin::query()
                ->where('domain', 'sms')
                ->where('slug', 'fake_sms_provider')
                ->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function removeSmsDriverBindings(): array
    {
        $bindings = DB::table('integration_plugin_bindings')
            ->where('domain', 'sms')
            ->where('binding_key', 'sms_driver')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        DB::table('integration_plugin_bindings')
            ->where('domain', 'sms')
            ->where('binding_key', 'sms_driver')
            ->delete();

        return array_values($bindings);
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     */
    private function restoreSmsDriverBindings(array $bindings): void
    {
        DB::table('integration_plugin_bindings')
            ->where('domain', 'sms')
            ->where('binding_key', 'sms_driver')
            ->delete();

        if ($bindings !== []) {
            DB::table('integration_plugin_bindings')->insert($bindings);
        }
    }

    private function ensureBindingTable(): void
    {
        if (Schema::hasTable('integration_plugin_bindings')) {
            return;
        }

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

final readonly class FeatureFakeSmsDriver implements SmsDriver
{
    public function __construct(
        private string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return '测试短信';
    }

    public function sendMessage(SmsMessageRequest $request): SmsSendResult
    {
        return new SmsSendResult('success');
    }

    public function sendVerifyCode(SmsSendRequest $request): SmsSendResult
    {
        return new SmsSendResult('success');
    }
}
