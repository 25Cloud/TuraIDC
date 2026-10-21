<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\ClientServiceConsole\ServiceVncService;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginConsolePurchaseService;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\System\OperationLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\RainyunRcs\Logic\RainyunRcs;

class RainyunRcsPurchaseTest extends TestCase
{
    private RainyunRcs $driver;

    private Supplier $supplier;

    private Service $service;

    private array $detail;

    protected function setUp(): void
    {
        parent::setUp();
        // 每例使用独立内存库，不修改开发库已有表与数据。
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('sqlite');
        foreach ([
            'services' => ['user_id', 'product_id', 'billing_cycle', 'amount', 'locked_pricing', 'status'],
            'products' => ['product_type'],
            'integration_plugins' => ['domain', 'slug'],
            'supplier_plugin_bindings' => ['supplier_id', 'plugin_id'],
            'service_upstream_bindings' => ['service_id', 'supplier_plugin_binding_id', 'plugin_id', 'provider_key', 'upstream_service_id'],
            'orders' => ['order_no', 'projection_type', 'user_id', 'product_id', 'service_id', 'product_spec_snapshot', 'product_type_snapshot', 'type', 'amount', 'discount', 'paid_amount', 'billing_cycle', 'quantity', 'status', 'config_snapshot', 'config_pricing_snapshot', 'paid_at'],
            'invoices' => ['invoice_no', 'order_id', 'user_id', 'product_id', 'service_id', 'product_spec_snapshot', 'product_type_snapshot', 'type', 'amount', 'discount', 'paid_amount', 'billing_cycle', 'quantity', 'status', 'due_date', 'config_snapshot', 'config_pricing_snapshot', 'trace_id', 'paid_at'],
        ] as $table => $fields) {
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->id();
                foreach ($fields as $field) {
                    $t->text($field)->nullable();
                }
                if (! in_array('trace_id', $fields, true)) {
                    $t->text('trace_id')->nullable();
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }
        DB::table('products')->insert(['id' => 10, 'product_type' => 'cloud_server']);
        DB::table('integration_plugins')->insert(['id' => 2, 'domain' => 'upstream', 'slug' => 'rainyun_rcs']);
        DB::table('supplier_plugin_bindings')->insert(['id' => 3, 'supplier_id' => 7, 'plugin_id' => 2]);
        DB::table('service_upstream_bindings')->insert(['id' => 4, 'service_id' => 5, 'supplier_plugin_binding_id' => 3,
            'plugin_id' => 2, 'provider_key' => 'rainyun_rcs', 'upstream_service_id' => 42]);
        $this->service = Service::query()->create(['id' => 5, 'user_id' => 1, 'product_id' => 10, 'billing_cycle' => 'monthly', 'amount' => '100.00', 'status' => 1]);
        // id 不在 fillable 内，显式固定业务夹具标识。
        DB::table('services')->where('id', $this->service->id)->update(['id' => 5]);
        $this->service = Service::query()->findOrFail(5);
        $this->supplier = (new Supplier)->forceFill(['id' => 7, 'api_url' => 'https://rainyun.example.invalid', 'api_key' => 'fixture-key',
            'provider_config' => ['traffic_price' => '0.02', 'disk_ssd_price' => '0.20', 'disk_backup_price' => '0.10']]);
        app(PluginFileLoader::class)->ensureLoaded(app(PluginScanner::class)->find('upstream', 'rainyun_rcs'));
        $this->driver = new RainyunRcs;
        $this->detail = ['Data' => ['ID' => 42, 'PlanID' => 10, 'ExpDate' => time() + 31 * 86400, 'Try' => false, 'Plan' => []], 'AvailableDiskType' => ['ssd'], 'EDiskList' => []];
        Http::preventStrayRequests();
        $this->mock(InvoiceService::class)->shouldReceive('syncProjection')->andReturnUsing(fn (Invoice $i) => $i->fresh());
        $this->mock(ServiceDetailService::class)->shouldReceive('resolveUpstreamContext')->andReturn([$this->driver, $this->supplier, 42, '']);
        Cache::flush();
    }

    public function test_checkout_requires_quote_and_reuses_existing_invoice(): void
    {
        $this->fakeUpstream();
        try {
            $this->submit(['func' => 'checkout', 'kind' => 'traffic', 'quantity' => 100]);
            $this->fail('未确认报价不能建单');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('先获取并确认报价', $e->getMessage());
        }
        $input = $this->confirmedInput();
        $a = $this->submit($input);
        $b = $this->submit($input);
        $this->assertSame($a['data']['invoice_id'], $b['data']['invoice_id']);
        $this->assertSame(1, Invoice::query()->count());
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_unpaid_invoice_cannot_fulfill(): void
    {
        $this->fakeUpstream();
        $invoice = $this->checkout();
        $this->expectException(BusinessException::class);
        app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
    }

    public function test_paid_purchase_fulfills_only_once(): void
    {
        $this->fakeUpstream();
        $input = $this->confirmedInput();
        $created = $this->submit($input);
        $invoice = Invoice::query()->findOrFail($created['data']['invoice_id']);
        $this->pay($invoice);
        $service = app(PluginConsolePurchaseService::class);
        $service->fulfill($invoice->order);
        $service->fulfill($invoice->order->fresh());
        $repeated = $this->submit($input);
        $this->assertSame($invoice->id, $repeated['data']['invoice_id']);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(OrderStatus::COMPLETED, (int) $invoice->order->fresh()->status);
        $writes = Http::recorded(fn (Request $r) => $r->method() === 'POST');
        $this->assertCount(1, $writes);
    }

    public function test_uncertain_write_never_repeats(): void
    {
        $this->fakeUpstream(502);
        $invoice = $this->checkout();
        $this->pay($invoice);
        foreach ([1, 2] as $attempt) {
            try {
                app(PluginConsolePurchaseService::class)->fulfill($invoice->order->fresh());
                $this->fail('结果不明不能标记成功');
            } catch (BusinessException) {
            }
        }
        $this->assertSame('uncertain', data_get($invoice->order->fresh()->config_pricing_snapshot, 'meta.state'));
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_changed_binding_cannot_execute_original_plugin_order(): void
    {
        $this->fakeUpstream();
        $invoice = $this->checkout();
        $this->pay($invoice);
        DB::table('service_upstream_bindings')->where('id', 4)->update(['plugin_id' => 88, 'provider_key' => 'other']);
        try {
            app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
            $this->fail('换绑必须拒绝旧订单');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('绑定已变更', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_foreign_supplier_cannot_use_rainyun_local_actions(): void
    {
        $this->fakeUpstream();
        $this->supplier->id = 8;
        try {
            $this->submit(['func' => 'terminal']);
            $this->fail('供应商不一致必须拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('绑定不一致', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_resource_change_after_payment_stops_fulfillment(): void
    {
        $this->fakeUpstream();
        $invoice = $this->checkout();
        $this->pay($invoice);
        $this->detail['Data']['PlanID'] = 99;
        try {
            app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
            $this->fail('资源变化必须拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('资源已变化', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_disk_purchase_updates_monthly_and_manual_renewal_prices(): void
    {
        $this->fakeUpstream();
        $this->service->forceFill(['locked_pricing' => ['monthly' => ['enabled' => true, 'base_amount' => '100.00', 'manual_amount' => '90.00']]])->save();
        $invoice = $this->checkout(['kind' => 'disk', 'operation' => 'create', 'disk_type' => 'ssd', 'size' => 50, 'backup' => false]);
        $this->pay($invoice);
        app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
        $this->service->refresh();
        $this->assertSame('110.00', $this->service->amount);
        $this->assertSame('100.00', $this->service->locked_pricing['monthly']['manual_amount']);
        $this->assertSame('330.00', $this->service->locked_pricing['quarterly']['base_amount']);
    }

    public function test_only_opted_in_runtime_returns_external_browser_console(): void
    {
        $url = 'https://test-vnc.raincs.cn/login?node=fixture&data=abc%2Bdef';
        Http::fake(['*' => Http::response(['code' => 200, 'data' => ['VNCProxyURL' => $url]])]);
        $result = $this->vncService($this->driver)->getVncUrlForUser((new User)->forceFill(['id' => 1]), 5);
        $this->assertSame($url, $result['url']);
        $this->assertSame('external', $result['launch_mode']);
        $this->assertArrayNotHasKey('vnc_credentials', $result);
    }

    public function test_zero_payable_disk_refund_creates_documents_once(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            foreach (['origin_invoice_id', 'remark', 'operator'] as $field) {
                $t->text($field)->nullable();
            }
        });
        foreach ([
            'account_transactions' => ['user_id', 'account_type', 'event_type', 'change_amount', 'balance_after', 'source_type', 'source_id', 'origin_type', 'origin_id', 'remark', 'operator', 'trace_id'],
            'refunds' => ['refund_no', 'user_id', 'invoice_id', 'payment_id', 'refund_invoice_id', 'amount', 'status', 'refund_method', 'currency', 'reason', 'operator_type', 'operator_id', 'operator_name', 'trace_id', 'refunded_at'],
        ] as $table => $fields) {
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->id();
                foreach ($fields as $field) {
                    $t->text($field)->nullable();
                }
                $t->timestamps();
            });
        }
        Schema::create('user_accounts', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            foreach (['cash_balance', 'credit_limit', 'referral_frozen_balance', 'referral_available_balance', 'referral_pending_withdrawal_balance', 'referral_withdrawn_balance'] as $field) {
                $t->decimal($field, 14, 2)->default(0);
            }
            $t->integer('version')->default(0);
            $t->timestamps();
        });
        $this->detail['EDiskList'] = [['ID' => 8, 'Slot' => 1, 'Size' => 50, 'DiskType' => 'ssd', 'Backup' => false]];
        $this->fakeUpstream();
        $input = $this->confirmedInput(['kind' => 'disk', 'operation' => 'delete', 'disk_id' => 8]);
        $created = $this->submit($input);
        $invoice = Invoice::query()->findOrFail($created['data']['invoice_id']);
        $this->assertSame('0.00', $invoice->amount);
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->status);
        $this->assertSame(OrderStatus::COMPLETED, (int) $invoice->order->status);
        app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
        // 即使上游详情尚未反映删盘结果，同一报价也不能再建单退款。
        $this->assertSame($invoice->id, $this->submit($input)['data']['invoice_id']);
        $this->assertSame('90.00', $this->service->fresh()->amount);
        $this->assertEquals(10, DB::table('user_accounts')->value('cash_balance'));
        $this->assertSame(1, DB::table('account_transactions')->count());
        $this->assertSame('invoice_refund', DB::table('account_transactions')->value('event_type'));
        $this->assertSame(1, DB::table('refunds')->count());
        $this->assertSame(1, Invoice::query()->where('type', 'refund')->count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_other_runtime_does_not_bypass_vnc_parameter_validation(): void
    {
        $runtime = new class
        {
            public function getVncUrl(...$args): array
            {
                return ['status' => 200, 'data' => ['url' => 'https://other.example.invalid/login?data=fixture']];
            }
        };
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('VNC 中转参数解析失败');
        $this->vncService($runtime)->getVncUrlForUser((new User)->forceFill(['id' => 1]), 5);
    }

    private function vncService(object $runtime): ServiceVncService
    {
        $detail = $this->createMock(ServiceDetailService::class);
        $detail->method('findUserService')->willReturn($this->service);
        $detail->method('resolveUpstreamContext')->willReturn([$runtime, $this->supplier, 42, '']);
        $detail->method('extractPayload')->willReturnCallback(fn (array $r) => $r['data']);
        $transform = $this->createMock(ServiceTransformService::class);
        $transform->method('canExecuteConsoleActions')->willReturn(true);
        $transform->method('transformDetail')->willReturn(['id' => 5]);

        return new ServiceVncService(
            $this->createMock(OperationLogService::class), $detail, $transform);
    }

    private function fakeUpstream(int $writeStatus = 200): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['code' => 200, 'data' => $this->detail])
            : Http::response(['code' => $writeStatus, 'data' => null], $writeStatus));
    }

    private function submit(array $input): array
    {
        return $this->driver->submitServiceConsoleAction($this->service, $this->supplier, 42, $input);
    }

    private function confirmedInput(array $input = ['kind' => 'traffic', 'quantity' => 100]): array
    {
        $quote = $this->submit($input + ['func' => 'quote']);

        return $input + ['func' => 'checkout', 'quote_token' => $quote['data']['quote_token']];
    }

    private function checkout(array $input = ['kind' => 'traffic', 'quantity' => 100]): Invoice
    {
        $result = $this->submit($this->confirmedInput($input));

        return Invoice::query()->findOrFail($result['data']['invoice_id']);
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->forceFill(['status' => InvoiceStatus::PAID, 'paid_amount' => $invoice->amount, 'paid_at' => now()])->save();
        $invoice->order->forceFill(['status' => OrderStatus::PAID, 'paid_at' => now()])->save();
    }
}
