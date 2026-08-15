<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationPlugin;
use App\Models\User;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientRechargeGatewayOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePluginTables();
        $this->cleanPluginTables();
        $this->forgetPaymentRuntime();
        config(['app.frontend_url' => 'https://pay.example.test']);
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        $this->forgetPaymentRuntime();

        parent::tearDown();
    }

    public function test_client_recharge_gateways_empty_when_no_payment_plugin_enabled(): void
    {
        Sanctum::actingAs($this->createClientUser());

        $this->getJson('/api/v2/client/recharge/gateways')
            ->assertOk()
            ->assertJsonPath('data.list', []);
    }

    public function test_client_recharge_gateways_only_include_enabled_ready_payment_plugins(): void
    {
        Sanctum::actingAs($this->createClientUser());
        $this->installPaymentPlugin('ali_pay', [
            'alipay_enabled' => true,
            'app_id' => 'alipay-app-id',
            'private_key' => 'alipay-private-key',
            'alipay_public_key' => 'alipay-public-key',
        ]);
        $this->activatePaymentPlugin('yi_pay', [
            'enabled' => true,
            'api_endpoint' => 'https://zpayz.cn',
            'merchant_id' => 'merchant-10001',
            'sign_type' => 'MD5',
            'merchant_key' => 'merchant-secret',
            'payment_types' => ['alipay', 'wxpay'],
        ]);

        $response = $this->getJson('/api/v2/client/recharge/gateways')
            ->assertOk()
            ->assertJsonPath('data.list.0.key', 'yipay')
            ->assertJsonPath('data.list.0.name', '易支付 - 支付宝')
            ->assertJsonPath('data.list.0.label', '支付宝')
            ->assertJsonPath('data.list.0.option_key', 'yipay:alipay')
            ->assertJsonPath('data.list.0.payment_type', 'alipay')
            ->assertJsonPath('data.list.1.key', 'yipay')
            ->assertJsonPath('data.list.1.name', '易支付 - 微信支付')
            ->assertJsonPath('data.list.1.label', '微信支付')
            ->assertJsonPath('data.list.1.option_key', 'yipay:wxpay')
            ->assertJsonPath('data.list.1.payment_type', 'wxpay')
            ->json('data.list');

        $this->assertSame(['yipay:alipay', 'yipay:wxpay'], collect($response)->pluck('option_key')->all());
    }

    public function test_client_recharge_rejects_when_no_payment_gateway_available(): void
    {
        Sanctum::actingAs($this->createClientUser());

        $this->postJson('/api/v2/client/recharge', [
            'amount' => 20,
        ])->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '当前没有可用支付方式，请联系管理员开启支付渠道');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function installPaymentPlugin(string $slug, array $config): IntegrationPlugin
    {
        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);

        $manifest = $scanner->requireManifest('payment', $slug);
        $plugin = $installer->install('payment', $slug);
        $configRepository->save($plugin, $manifest, $config);

        $this->forgetPaymentRuntime();

        return $plugin->fresh('config') ?? $plugin;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function activatePaymentPlugin(string $slug, array $config): IntegrationPlugin
    {
        $plugin = $this->installPaymentPlugin($slug, $config);
        $enabled = app(PluginInstaller::class)->enable($plugin);
        $this->forgetPaymentRuntime();

        return $enabled;
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'recharge-gateway-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '15'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Gateway',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function forgetPaymentRuntime(): void
    {
        $this->app->forgetInstance(PluginRuntimeRegistry::class);
        $this->app->forgetInstance(PaymentGatewayRegistry::class);
        $this->app->forgetInstance(PaymentGatewayManager::class);
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
