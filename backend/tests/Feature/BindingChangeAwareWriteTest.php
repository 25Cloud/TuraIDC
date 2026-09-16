<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Upstream\ProviderKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 绑定表写入放大回归。
 *
 * supplier_plugin_bindings / product_upstream_bindings 与服务级绑定、快照表
 * 会被「定时库存同步 + 每次服务详情访问」反复重写：商品库存同步每个槽位为全部
 * 已绑定商品回写快照，服务详情每次访问都会走 ServiceUpstreamBindingWriter::syncServiceState。
 * 数据没变时若仍发 UPDATE，MySQL 会为每行写入完整的前后镜像 binlog
 * （生产实测两张绑定表每天约 9GB），因此这里把「无变化不写库」固化成断言。
 */
class BindingChangeAwareWriteTest extends TestCase
{
    /** @var list<array{sql: string, bindings: array<int, mixed>}> */
    private array $recordedStatements = [];

    private bool $listening = false;

    private ?int $supplierId = null;

    private ?int $productId = null;

    private ?int $serviceId = null;

    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    protected function tearDown(): void
    {
        if ($this->serviceId !== null) {
            foreach ([
                'service_provision_attempts',
                'service_connection_snapshots',
                'service_runtime_snapshots',
                'service_upstream_bindings',
            ] as $table) {
                DB::table($table)->where('service_id', $this->serviceId)->delete();
            }

            DB::table('services')->where('id', $this->serviceId)->delete();
        }

        if ($this->userId !== null) {
            DB::table('users')->where('id', $this->userId)->delete();
        }

        if ($this->productId !== null) {
            DB::table('product_upstream_bindings')->where('product_id', $this->productId)->delete();
            DB::table('products')->where('id', $this->productId)->delete();
        }

        if ($this->supplierId !== null) {
            DB::table('supplier_plugin_bindings')->where('supplier_id', $this->supplierId)->delete();
            DB::table('suppliers')->where('id', $this->supplierId)->delete();
        }

        parent::tearDown();
    }

    public function test_supplier_binding_sync_does_not_write_when_nothing_changed(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = $this->createSupplier($suffix);
        $writer = app(UpstreamBindingWriter::class);

        $bindingId = $writer->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://binding-'.$suffix.'.example.com',
            'account_name' => 'acct-'.$suffix,
            'api_key' => 'secret-'.$suffix,
            'status' => 1,
            'priority' => 3,
        ]);
        $this->assertIsInt($bindingId);
        $this->assertGreaterThan(0, $bindingId);

        $secretBefore = (string) DB::table('supplier_plugin_bindings')->where('id', $bindingId)->value('secret_json');
        $this->assertNotSame('', $secretBefore);

        $this->startRecording();

        // 生产热路径：ProductSyncService / ServiceUpstreamBindingWriter 只传供应商模型，
        // 其余字段全部回落到既有绑定，属于「确保绑定存在」的重复调用。
        $resolvedId = $writer->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://binding-'.$suffix.'.example.com',
            'account_name' => 'acct-'.$suffix,
            'api_key' => 'secret-'.$suffix,
            'status' => 1,
            'priority' => 3,
        ]);
        $writer->syncSupplierBinding($supplier);

        $this->assertSame($bindingId, $resolvedId);
        $this->assertSame([], $this->updatesOn('supplier_plugin_bindings'), '数据未变化时不应产生 UPDATE');
        $this->assertSame(
            $secretBefore,
            (string) DB::table('supplier_plugin_bindings')->where('id', $bindingId)->value('secret_json'),
            '密钥明文未变时必须复用原密文，随机 IV 不应造成行内容变化'
        );
    }

    public function test_supplier_binding_sync_writes_when_credentials_change(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = $this->createSupplier($suffix);
        $writer = app(UpstreamBindingWriter::class);

        $bindingId = $writer->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://binding-'.$suffix.'.example.com',
            'account_name' => 'acct-'.$suffix,
            'api_key' => 'secret-'.$suffix,
            'status' => 1,
        ]);
        $this->assertIsInt($bindingId);

        $newApiKey = 'rotated-'.$suffix;
        $this->startRecording();

        $writer->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://binding-'.$suffix.'.example.com',
            'account_name' => 'acct-'.$suffix,
            'api_key' => $newApiKey,
            'status' => 1,
        ]);

        $this->assertCount(1, $this->updatesOn('supplier_plugin_bindings'), '密钥变化必须落库');

        $cipher = (string) DB::table('supplier_plugin_bindings')->where('id', $bindingId)->value('secret_json');
        $this->assertSame($newApiKey, (string) data_get(json_decode(Crypt::decryptString($cipher), true), 'api_key'));
    }

    public function test_product_binding_sync_does_not_write_when_only_sync_timestamp_moves(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$supplier, $product] = $this->createSupplierAndProduct($suffix);
        $writer = app(UpstreamBindingWriter::class);

        $snapshot = [
            'stock' => 7,
            'source' => 'scheduled_stock_sync',
            'synced_at' => '2026-09-16 10:00:00',
        ];
        $bindingId = $writer->syncProductBinding($product, $supplier, '1001', $snapshot);
        $this->assertIsInt($bindingId);

        $this->startRecording();

        // 两个槽位的库存同步：库存没变，只有快照里的 synced_at 前移。
        $againId = $writer->syncProductBinding($product, $supplier, '1001', [
            'stock' => 7,
            'source' => 'scheduled_stock_sync',
            'synced_at' => '2026-09-16 10:15:00',
        ]);

        $this->assertSame($bindingId, $againId);
        $this->assertSame([], $this->updatesOn('product_upstream_bindings'), '库存未变化时不应产生 UPDATE');
        $this->assertSame([], $this->updatesOn('supplier_plugin_bindings'), '商品绑定同步不应顺带重写供应商绑定');
    }

    public function test_product_binding_sync_writes_when_stock_changes(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$supplier, $product] = $this->createSupplierAndProduct($suffix);
        $writer = app(UpstreamBindingWriter::class);

        $bindingId = $writer->syncProductBinding($product, $supplier, '1001', [
            'stock' => 7,
            'source' => 'scheduled_stock_sync',
            'synced_at' => '2026-09-16 10:00:00',
        ]);
        $this->assertIsInt($bindingId);

        $this->startRecording();

        $writer->syncProductBinding($product, $supplier, '1001', [
            'stock' => 3,
            'source' => 'scheduled_stock_sync',
            'synced_at' => '2026-09-16 10:15:00',
        ]);

        $this->assertCount(1, $this->updatesOn('product_upstream_bindings'), '库存变化必须落库');

        $snapshot = json_decode(
            (string) DB::table('product_upstream_bindings')->where('id', $bindingId)->value('upstream_product_snapshot_json'),
            true
        );
        $this->assertSame(3, (int) data_get($snapshot, 'stock'));
    }

    public function test_service_binding_and_snapshots_do_not_write_when_nothing_changed(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$supplier, $product] = $this->createSupplierAndProduct($suffix);
        $writer = app(UpstreamBindingWriter::class);
        $writer->syncProductBinding($product, $supplier, '1001', ['stock' => 7, 'source' => 'product_sync']);

        $service = $this->createService($suffix, $supplier, $product);
        $serviceWriter = app(ServiceUpstreamBindingWriter::class);

        $baseProvisionData = [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_host_id' => '9527',
            'upstream_account_id' => 'acct-'.$suffix,
            'upstream_status' => 'Active',
            'runtime_status' => 'active',
            'runtime_description' => '运行中',
            'dedicated_ip' => '192.0.2.10',
            'username' => 'root',
            'password' => 'p@ssword',
            'nat_remote_port' => 22022,
        ];

        $bindingId = $serviceWriter->syncServiceState($service, $product, $baseProvisionData);
        $this->assertIsInt($bindingId);
        $this->assertGreaterThan(0, $bindingId);

        $this->startRecording();

        // 同一份状态再来一轮：只有各类「最近同步时间」前移，业务字段完全一致。
        $serviceWriter->syncServiceState($service, $product, array_merge($baseProvisionData, [
            'last_synced_at' => '2026-09-16 11:20:00',
            'last_status_sync_at' => '2026-09-16 11:20:00',
            'nat_remote_checked_at' => '2026-09-16 11:20:00',
            'connection_cached_at' => '2026-09-16 11:20:00',
        ]));

        $this->assertSame([], $this->updatesOn('service_upstream_bindings'));
        $this->assertSame([], $this->updatesOn('service_runtime_snapshots'));
        $this->assertSame([], $this->updatesOn('service_connection_snapshots'));

        // 状态真正变化时仍必须落库，避免把「不写」做成「永不写」。
        $this->startRecording();

        $serviceWriter->syncServiceState($service, $product, array_merge($baseProvisionData, [
            'runtime_status' => 'suspended',
            'runtime_description' => '已暂停',
        ]));

        $this->assertNotEmpty($this->updatesOn('service_upstream_bindings'));
        $this->assertNotEmpty($this->updatesOn('service_runtime_snapshots'));
    }

    private function startRecording(): void
    {
        $this->recordedStatements = [];

        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function ($query): void {
            $sql = trim((string) $query->sql);
            if (preg_match('/^update\b/i', $sql) === 1) {
                $this->recordedStatements[] = ['sql' => $sql, 'bindings' => $query->bindings];
            }
        });
    }

    /**
     * @return list<string>
     */
    private function updatesOn(string $table): array
    {
        return array_values(array_map(
            static fn (array $statement): string => $statement['sql'],
            array_filter(
                $this->recordedStatements,
                static fn (array $statement): bool => str_contains($statement['sql'], $table)
            )
        ));
    }

    private function createSupplier(string $suffix): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => 'Binding Amplification Supplier '.$suffix,
            'code' => 'binding-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://binding-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 2,
        ]);

        $this->supplierId = (int) $supplier->id;

        return $supplier;
    }

    /**
     * @return array{0: Supplier, 1: Product}
     */
    private function createSupplierAndProduct(string $suffix): array
    {
        $supplier = $this->createSupplier($suffix);

        // 供应商绑定由管理端保存供应商时建立；没有它 syncProductBinding 无法解析 plugin_id。
        $supplierBindingId = app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => 'https://binding-'.$suffix.'.example.com',
            'account_name' => 'acct-'.$suffix,
            'api_key' => 'secret-'.$suffix,
            'status' => 1,
        ]);
        $this->assertIsInt($supplierBindingId);

        $product = Product::query()->create([
            'name' => 'Binding Amplification Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '19.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 1001,
        ]);

        $this->productId = (int) $product->id;

        return [$supplier, $product];
    }

    private function createService(string $suffix, Supplier $supplier, Product $product): Service
    {
        $user = User::query()->create([
            'email' => 'binding-amplification-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Binding Amplification Service '.$suffix,
            'domain' => $suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '19.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider' => ProviderKey::ZJMF_FINANCE_API,
                'supplier_id' => (int) $supplier->id,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $this->serviceId = (int) $service->id;
        $this->userId = (int) $user->id;

        return $service;
    }
}
