<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\IntegrationPlugin;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginProviderRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 自助退款插件的测试夹具（照 zjmf_bridge/tests/Support/InstallsZjmfBridgeAddon.php 的写法）。
 *
 * 四件事：
 * 1. 必要时把插件登记表建出来（干净测试库也能跑）；
 * 2. 台账表与商品策略表由**核心迁移**创建，测试直接跑迁移里的 `up()`，测真 DDL 而不是另抄一份；
 * 3. 安装 + 写配置 + 启用 + 显式激活 Provider（路由在测试进程内才会存在）；
 * 4. 造「已付款 + 已开通 + 已绑定上游 + 带商品退款策略」的服务，供四类退款用例复用。
 */
trait InstallsAutoRefundAddon
{
    /** 夹具用的假上游地址（.test 域名，永远不会真的解析出去） */
    protected const TEST_API_BASE_URL = 'https://upstream.example.test/plugins/addons/auto_refund/api.php';

    /** 夹具用的假密钥（不含任何真实凭据） */
    protected const TEST_API_KEY = 'test-api-key-not-real';

    /** 夹具用的假上游站点（工单接口） */
    protected const TEST_TICKET_HOSTNAME = 'https://upstream.example.test';

    /** 夹具用的假工单接口账号与密钥 */
    protected const TEST_TICKET_USERNAME = 'downstream-agent@example.test';

    protected const TEST_TICKET_API_KEY = 'test-ticket-api-key-not-real';

    protected const TEST_TICKET_DEPARTMENT_ID = '2';

    /**
     * @param  array<string, mixed>  $config
     */
    protected function installAutoRefundAddon(bool $enable = true, array $config = []): IntegrationPlugin
    {
        $this->ensureAutoRefundPluginTables();
        $this->ensureAutoRefundBusinessTables();

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);

        $manifest = $scanner->requireManifest(PluginDomain::ADDONS, 'auto_refund');
        $plugin = $installer->install(PluginDomain::ADDONS, 'auto_refund');

        // 两条出站通道的凭据都给足：`bridge` 与 `api_ticket` 的用例都靠它，
        // 要测「未配置」状态请用 wipeAutoRefundConfig()。
        $configRepository->save($plugin, $manifest, array_merge([
            'enabled' => true,
            'api_base_url' => self::TEST_API_BASE_URL,
            'api_key' => self::TEST_API_KEY,
            'ticket_protocol' => 'zjfm',
            'ticket_hostname' => self::TEST_TICKET_HOSTNAME,
            'ticket_username' => self::TEST_TICKET_USERNAME,
            'ticket_api_key' => self::TEST_TICKET_API_KEY,
            'ticket_department_id' => self::TEST_TICKET_DEPARTMENT_ID,
        ], $config));

        if (! $enable) {
            return $installer->disable($plugin);
        }

        $plugin = $installer->enable($plugin);

        // 路由由插件 Provider 注册：库里启用后还要在本进程里显式激活一次
        app(PluginProviderRegistry::class)->activate($manifest);

        return $plugin;
    }

    /**
     * 清掉插件配置行，模拟「装了插件但没填上游地址/密钥」。
     */
    protected function wipeAutoRefundConfig(): void
    {
        $plugin = IntegrationPlugin::query()
            ->where('domain', PluginDomain::ADDONS)
            ->where('slug', 'auto_refund')
            ->firstOrFail();

        DB::table('integration_plugin_configs')
            ->where('plugin_id', (int) $plugin->id)
            ->delete();

        Cache::flush();
    }

    /**
     * 改写插件配置（已装好时用）。
     *
     * @param  array<string, mixed>  $config
     */
    protected function configureAutoRefund(array $config): void
    {
        $scanner = app(PluginScanner::class);
        $manifest = $scanner->requireManifest(PluginDomain::ADDONS, 'auto_refund');
        $plugin = IntegrationPlugin::query()
            ->where('domain', PluginDomain::ADDONS)
            ->where('slug', 'auto_refund')
            ->firstOrFail();

        app(PluginConfigRepository::class)->save($plugin, $manifest, $config);

        // 插件记录有 60 秒缓存，配置改动后必须清掉，否则同一测试进程里会读到旧记录
        Cache::flush();
    }

    private function ensureAutoRefundBusinessTables(): void
    {
        $migrations = [
            '2026_11_01_000001_create_auto_refund_applications_table.php',
            '2026_11_03_000001_create_auto_refund_product_rules_table.php',
            '2026_11_03_000002_add_channel_and_ticket_to_auto_refund_applications.php',
        ];

        if (Schema::hasTable('auto_refund_applications')
            && Schema::hasTable('auto_refund_product_rules')
            && Schema::hasColumn('auto_refund_applications', 'refund_type')) {
            return;
        }

        foreach ($migrations as $file) {
            $migration = require dirname(__DIR__, 5).'/database/migrations/'.$file;

            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }

        if (Schema::hasTable('auto_refund_applications') && ! Schema::hasColumn('auto_refund_applications', 'refund_type')) {
            throw new \RuntimeException('auto_refund_applications 缺少 refund_type 列，迁移未生效');
        }
    }

    private function ensureAutoRefundPluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('manifest_hash', 64)->nullable();
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
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
    }

    /**
     * 造一个客户端用户。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRefundUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    /**
     * 造一级 → 二级 → 三级分组链，返回三级分组 ID（商品挂 `product_group_id` 用的就是它）。
     */
    protected function makeProductGroupTree(string $label = '默认分组'): int
    {
        $suffix = strtoupper(bin2hex(random_bytes(3)));

        $first = FirstProductGroup::query()->create([
            // `code` 上有唯一索引：多组用例会各建一棵树，不能都写 `vps`
            'code' => 'grp-'.strtolower($suffix),
            'product_type' => 'cloud_server',
            'name' => $label.'-一级-'.$suffix,
            'slug' => 'first-'.strtolower($suffix),
        ]);

        $second = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $first->id,
            'name' => $label.'-二级-'.$suffix,
            'slug' => 'second-'.strtolower($suffix),
        ]);

        $third = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $second->id,
            'name' => $label.'-三级-'.$suffix,
            'slug' => 'third-'.strtolower($suffix),
        ]);

        return (int) $third->id;
    }

    /**
     * 造一台「已付款 + 已开通 + 已绑定上游 + 有商品退款策略」的服务，供退款申请使用。
     *
     * @param  array{
     *     amount?: string,
     *     service_status?: int,
     *     invoice_status?: int,
     *     payment_status?: int,
     *     host_id?: string,
     *     created_days_ago?: int,
     *     expires_in_days?: int,
     *     with_payment?: bool,
     *     product_group_id?: int|null,
     *     product?: Product|null,
     *     refund_type?: string,
     *     refund_rule?: string,
     *     requirement?: string,
     *     window_hours?: int,
     *     rule_enabled?: bool,
     *     with_rule?: bool
     * }  $options
     * @return array{service: Service, product: Product, order: Order, invoice: Invoice, payment: Payment|null, rule: AutoRefundProductRule|null}
     */
    protected function makeRefundService(User $user, array $options = []): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $amount = (string) ($options['amount'] ?? '100.00');
        $hostId = (string) ($options['host_id'] ?? (string) random_int(1000, 999999));
        $createdDaysAgo = (int) ($options['created_days_ago'] ?? 3);
        $expiresInDays = (int) ($options['expires_in_days'] ?? 27);

        $product = $options['product'] ?? null;
        if (! $product instanceof Product) {
            $product = Product::query()->create([
                'product_type' => 'cloud_server',
                // products 表没有 name 列：商品展示名就是 custom_display_name
                'custom_display_name' => '香港 CN2',
                'remark' => '香港 CN2',
                'pricing' => ['monthly' => $amount],
                'product_group_id' => $options['product_group_id'] ?? null,
            ]);
        }

        $order = Order::query()->create([
            'order_no' => 'ARO'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'amount' => $amount,
            'paid_amount' => $amount,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PAID,
            'paid_at' => now()->subDays($createdDaysAgo),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ARI'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => $amount,
            'paid_amount' => $amount,
            'status' => (int) ($options['invoice_status'] ?? InvoiceStatus::PAID),
            'due_date' => now()->addDay(),
            'paid_at' => now()->subDays($createdDaysAgo),
        ]);

        $payment = null;
        if (($options['with_payment'] ?? true) === true) {
            $paymentNo = Payment::generatePaymentNo();
            // 必须用第三方网关：Payment 模型禁止把余额/手工/免费流程记成支付单
            Payment::query()->create([
                'payment_no' => $paymentNo,
                'user_id' => (int) $user->id,
                'order_id' => (int) $order->id,
                'invoice_id' => (int) $invoice->id,
                'gateway' => 'alipay',
                'trade_no' => 'ALIPAYTEST'.$suffix,
                'amount' => $amount,
                'status' => (int) ($options['payment_status'] ?? PaymentStatus::SUCCESS),
                'callback_raw' => ['source' => 'auto_refund_test'],
                'paid_at' => now()->subDays($createdDaysAgo),
            ]);
            $payment = Payment::query()->where('payment_no', $paymentNo)->first();
        }

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'name' => '香港-CN2-'.$suffix,
            'domain' => 'hk'.strtolower($suffix).'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => $amount,
            'status' => (int) ($options['service_status'] ?? ServiceStatus::ACTIVE),
            'provision_data' => [
                'upstream_host_id' => $hostId,
                'dedicated_ip' => '192.0.2.10',
                'assigned_ips' => [],
            ],
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        // created_at 不在 $fillable 里，开通时间只能事后写
        $service->forceFill([
            'created_at' => now()->subDays($createdDaysAgo),
            'updated_at' => now()->subDays($createdDaysAgo),
        ])->save();
        $service->refresh();

        $rule = null;
        if (($options['with_rule'] ?? true) === true) {
            $rule = $this->makeProductRule((int) $product->id, $options);
        }

        return [
            'service' => $service,
            'product' => $product,
            'order' => $order,
            'invoice' => $invoice,
            'payment' => $payment,
            'rule' => $rule,
        ];
    }

    /**
     * 写一条商品退款策略（默认 `bridge` + 全额 + 同商品首次）。
     *
     * 默认值刻意选「插件间对接 + 全额」：第一阶段那批用例的语义就是「提交到上游 api.php、
     * 金额等于账单实付」，换成别的默认值会让它们变成在测另一件事。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeProductRule(int $productId, array $overrides = []): AutoRefundProductRule
    {
        return AutoRefundProductRule::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'refund_type' => (string) ($overrides['refund_type'] ?? AutoRefundProductRule::TYPE_BRIDGE),
                'refund_rule' => (string) ($overrides['refund_rule'] ?? AutoRefundProductRule::RULE_FULL),
                'requirement' => (string) ($overrides['requirement'] ?? AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST),
                'window_hours' => (int) ($overrides['window_hours'] ?? 0),
                'enabled' => (bool) ($overrides['rule_enabled'] ?? true),
            ],
        );
    }

    /**
     * 给服务补一张指定类型的账单（`renew` 续费 / `upgrade` 升降级），可选带明细投影行。
     *
     * @param  array{with_item?: bool, status?: int}  $options
     */
    protected function makeServiceInvoice(Service $service, string $invoiceType, array $options = []): Invoice
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ARX'.$suffix,
            'user_id' => (int) $service->user_id,
            'order_id' => $service->order_id === null ? null : (int) $service->order_id,
            'service_id' => (int) $service->id,
            'type' => $invoiceType,
            'amount' => '50.00',
            'paid_amount' => '50.00',
            'status' => (int) ($options['status'] ?? InvoiceStatus::PAID),
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        if (($options['with_item'] ?? true) === true) {
            DB::table('invoice_items')->insert([
                'invoice_id' => (int) $invoice->id,
                'item_name' => '账单项目',
                'item_type' => $invoiceType,
                'quantity' => 1,
                'unit_price' => '50.00',
                'discount_amount' => '0.00',
                'line_amount' => '50.00',
                'meta_json' => json_encode(['source' => 'auto_refund_test']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invoice;
    }

    /**
     * 直接落一条台账行（不经过业务链路），供「已有申请」类用例使用。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeApplicationRow(User $user, int $serviceId, array $overrides = []): AutoRefundApplication
    {
        return AutoRefundApplication::query()->create(array_merge([
            'application_no' => 'AR'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'service_id' => $serviceId,
            'upstream_product_id' => '9000',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'refund_type' => AutoRefundProductRule::TYPE_BRIDGE,
            'channel' => AutoRefundApplication::CHANNEL_BRIDGE,
            'active_service_id' => $serviceId,
            'reason' => '测试台账',
            'estimated_amount' => '10.00',
        ], $overrides));
    }
}
