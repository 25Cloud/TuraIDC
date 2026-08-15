<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceUpgradeService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\System\OperationLogService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceUpgradeServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_create_host_upgrade_invoice_for_user_builds_upgrade_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'host-upgrade-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Upgrade Supplier '.$suffix,
            'code' => 'upgrade-supplier-'.$suffix,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://upgrade-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Upgrade Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Upgrade Service '.$suffix,
            'domain' => 'upgrade-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'upstream_host_id' => 778899,
                'provider' => 'zjmf_finance_api',
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $detailService = $this->createMock(ServiceDetailService::class);
        $detailService->method('findUserService')->willReturn($service);
        $detailService->method('resolveUpstreamContext')->willReturn([
            new class
            {
                public function previewHostUpgrade($supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
                {
                    return [
                        'status' => 200,
                        'data' => [
                            'name' => '升级到高配',
                            'amount_total' => '88.80',
                            'billingcycle' => $billingCycle,
                            'promo_code' => 'PROMO88',
                        ],
                    ];
                }
            },
            $supplier,
            778899,
            'jwt-token',
        ]);
        $detailService->method('assertSuccess');
        $detailService->method('extractPayload')->willReturn([
            'name' => '升级到高配',
            'amount_total' => '88.80',
            'billingcycle' => 'monthly',
            'promo_code' => 'PROMO88',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

        $serviceUpgradeService = new ServiceUpgradeService(
            $detailService,
            app(InvoiceService::class),
            $operationLogService,
        );

        $invoice = $serviceUpgradeService->createInvoiceForUser($user, (int) $service->id, [
            'product_id' => 5566,
            'billing_cycle' => 'monthly',
            'promo_code' => 'PROMO88',
        ], [
            'actor_type' => 'client',
            'actor_user_id' => (int) $user->id,
            'actor_name' => (string) $user->email,
            'trace_id' => 'host-upgrade-'.$suffix,
        ]);

        $this->assertSame(InvoiceStatus::UNPAID, (int) $invoice->status);
        $this->assertSame('88.80', number_format((float) $invoice->amount, 2, '.', ''));
        $this->assertSame('host_upgrade', (string) data_get($invoice->config_pricing_snapshot ?? [], 'meta.kind', ''));
        $this->assertSame(5566, (int) data_get($invoice->config_pricing_snapshot ?? [], 'meta.product_id', 0));
        $this->assertSame('PROMO88', (string) data_get($invoice->config_pricing_snapshot ?? [], 'meta.promo_code', ''));

        $order = Order::query()->findOrFail((int) $invoice->fresh()->order_id);

        $this->assertSame((int) $invoice->id, (int) $order->invoice?->id);
        $this->assertSame((int) $user->id, (int) $order->user_id);
        $this->assertSame((int) $product->id, (int) $order->product_id);
        $this->assertSame((int) $service->id, (int) $order->service_id);
        $this->assertSame('upgrade', (string) $order->type);
        $this->assertSame(OrderStatus::PENDING, (int) $order->status);
        $this->assertSame('88.80', number_format((float) $order->amount, 2, '.', ''));
        $this->assertSame('host-upgrade-'.$suffix, (string) $order->trace_id);
        $this->assertSame('host_upgrade', (string) data_get($order->config_pricing_snapshot ?? [], 'meta.kind', ''));
    }

    public function test_paid_host_upgrade_invoice_dispatches_its_order_to_host_upgrade_fulfillment(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $originalQueueDriver = config('queue.default');
        Config::set('queue.default', 'sync');

        try {
            $user = User::query()->create([
                'email' => 'host-upgrade-payment-'.$suffix.'@example.com',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
            $user->forceFill(['balance' => '200.00'])->save();

            $supplier = Supplier::query()->create([
                'name' => 'Upgrade Payment Supplier '.$suffix,
                'code' => 'upgrade-payment-supplier-'.$suffix,
                'interface_type' => 'zjmf_finance_api',
                'api_url' => 'https://upgrade-payment-'.$suffix.'.example.test',
                'api_username' => 'demo',
                'api_key' => 'secret',
                'status' => 1,
            ]);

            $product = Product::query()->create([
                'name' => 'Upgrade Payment Product '.$suffix,
                'product_type' => 'vps',
                'pricing' => ['monthly' => '10.00'],
                'setup_fee' => '0.00',
                'config_options' => [],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 0,
                'supplier_id' => (int) $supplier->id,
            ]);

            $service = Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'name' => 'Upgrade Payment Service '.$suffix,
                'domain' => 'upgrade-payment-'.$suffix.'.example.com',
                'billing_cycle' => 'monthly',
                'amount' => '10.00',
                'status' => ServiceStatus::ACTIVE,
                'provision_data' => [
                    'upstream_host_id' => 778899,
                    'provider' => 'zjmf_finance_api',
                ],
                'expires_at' => now()->addMonth(),
                'auto_renew' => 0,
            ]);

            $detailService = $this->createMock(ServiceDetailService::class);
            $detailService->method('findUserService')->willReturn($service);
            $detailService->method('resolveUpstreamContext')->willReturn([
                new class
                {
                    public function previewHostUpgrade($supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
                    {
                        return [
                            'status' => 200,
                            'data' => [
                                'name' => '升级到高配',
                                'amount_total' => '88.80',
                                'billingcycle' => $billingCycle,
                            ],
                        ];
                    }
                },
                $supplier,
                778899,
                'jwt-token',
            ]);
            $detailService->method('assertSuccess');
            $detailService->method('extractPayload')->willReturn([
                'name' => '升级到高配',
                'amount_total' => '88.80',
                'billingcycle' => 'monthly',
            ]);

            $operationLogService = $this->createMock(OperationLogService::class);
            $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

            $invoiceFactory = new ServiceUpgradeService(
                $detailService,
                app(InvoiceService::class),
                $operationLogService,
            );

            $invoice = $invoiceFactory->createInvoiceForUser($user, (int) $service->id, [
                'product_id' => 5566,
                'billing_cycle' => 'monthly',
            ], [
                'actor_type' => 'client',
                'actor_user_id' => (int) $user->id,
                'actor_name' => (string) $user->email,
                'trace_id' => 'host-upgrade-payment-'.$suffix,
            ]);

            $order = Order::query()->findOrFail((int) $invoice->fresh()->order_id);
            $this->assertSame('upgrade', (string) $order->type);
            $this->assertSame('host_upgrade', (string) data_get($order->config_pricing_snapshot ?? [], 'meta.kind', ''));

            $hostUpgradeProcessor = $this->createMock(ServiceUpgradeService::class);
            $hostUpgradeProcessor->expects($this->once())
                ->method('processPaidUpgradeOrder')
                ->with($this->callback(function (Order $candidate) use ($order, $service): bool {
                    return (int) $candidate->id === (int) $order->id
                        && (int) $candidate->service_id === (int) $service->id
                        && (string) $candidate->type === 'upgrade'
                        && (string) data_get($candidate->config_pricing_snapshot ?? [], 'meta.kind', '') === 'host_upgrade';
                }))
                ->willReturnCallback(function (Order $candidate) use ($service): Service {
                    $candidate->forceFill(['status' => OrderStatus::COMPLETED])->save();

                    return $service;
                });

            $trafficPackageProcessor = $this->createMock(ServiceTrafficPackageService::class);
            $trafficPackageProcessor->expects($this->never())->method('processPaidTrafficPackageInvoice');
            $trafficPackageProcessor->expects($this->never())->method('processPaidTrafficPackageOrder');

            $this->app->instance(ServiceUpgradeService::class, $hostUpgradeProcessor);
            $this->app->instance(ServiceTrafficPackageService::class, $trafficPackageProcessor);

            app(PaymentService::class)->payByBalance($invoice, $user, [
                'trace_id' => 'host-upgrade-payment-paid-'.$suffix,
            ]);

            $this->assertSame(InvoiceStatus::PAID, (int) $invoice->fresh()->status);
            $this->assertSame(OrderStatus::COMPLETED, (int) $order->fresh()->status);
        } finally {
            Config::set('queue.default', $originalQueueDriver);
            $this->app->forgetInstance(ServiceUpgradeService::class);
            $this->app->forgetInstance(ServiceTrafficPackageService::class);
        }
    }

    public function test_unpaid_host_upgrade_invoice_without_order_recovers_one_pending_upgrade_order(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $traceId = 'host-upgrade-recovery-'.$suffix;

        $user = User::query()->create([
            'email' => 'host-upgrade-recovery-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Upgrade Recovery Supplier '.$suffix,
            'code' => 'upgrade-recovery-supplier-'.$suffix,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://upgrade-recovery-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Upgrade Recovery Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Upgrade Recovery Service '.$suffix,
            'domain' => 'upgrade-recovery-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVUPGREC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '恢复主机升降级订单',
            'product_type_snapshot' => 'vps',
            'service_id' => (int) $service->id,
            'type' => 'upgrade',
            'amount' => '88.80',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [
                'upgrade_product_id' => 5566,
                'billing_cycle' => 'monthly',
            ],
            'config_pricing_snapshot' => [
                'base_amount' => '0.00',
                'config_amount' => '88.80',
                'meta' => [
                    'kind' => 'host_upgrade',
                    'mode' => 'host_upgrade',
                    'product_id' => 5566,
                    'billing_cycle' => 'monthly',
                    'promo_code' => 'RECOVER88',
                    'upstream_host_id' => 778899,
                ],
            ],
            'coupon_snapshot' => [],
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
            'trace_id' => $traceId,
        ]);

        $this->assertNull($invoice->order_id);

        $serviceUpgradeService = new ServiceUpgradeService(
            $this->createMock(ServiceDetailService::class),
            app(InvoiceService::class),
            $this->createMock(OperationLogService::class),
        );

        $recoveredOrder = $serviceUpgradeService->ensureHostUpgradeOrderForInvoice($invoice);
        $recoveredAgain = $serviceUpgradeService->ensureHostUpgradeOrderForInvoice($invoice->fresh());

        $this->assertInstanceOf(Order::class, $recoveredOrder);
        $this->assertInstanceOf(Order::class, $recoveredAgain);
        $this->assertSame((int) $recoveredOrder->id, (int) $recoveredAgain->id);
        $this->assertSame((int) $recoveredOrder->id, (int) $invoice->fresh()->order_id);
        $this->assertSame((int) $user->id, (int) $recoveredOrder->user_id);
        $this->assertSame((int) $product->id, (int) $recoveredOrder->product_id);
        $this->assertSame((int) $service->id, (int) $recoveredOrder->service_id);
        $this->assertSame('upgrade', (string) $recoveredOrder->type);
        $this->assertSame(OrderStatus::PENDING, (int) $recoveredOrder->status);
        $this->assertSame('88.80', number_format((float) $recoveredOrder->amount, 2, '.', ''));
        $this->assertSame($traceId, (string) $recoveredOrder->trace_id);
        $this->assertSame('host_upgrade', (string) data_get($recoveredOrder->config_pricing_snapshot ?? [], 'meta.kind', ''));
        $this->assertSame(1, Order::query()
            ->where('user_id', (int) $user->id)
            ->where('trace_id', $traceId)
            ->count());
    }

    public function test_process_paid_upgrade_order_uses_new_upgrade_chain(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'host-upgrade-paid-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Upgrade Paid Supplier '.$suffix,
            'code' => 'upgrade-paid-supplier-'.$suffix,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://upgrade-paid-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Upgrade Paid Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Upgrade Paid Service '.$suffix,
            'domain' => 'upgrade-paid-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
        $this->createNormalizedServiceBinding($supplier, $product, $service, 5566, 778899);

        $order = Order::query()->create([
            'order_no' => 'UPG'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '升级订单',
            'product_type_snapshot' => 'vps',
            'service_id' => (int) $service->id,
            'type' => 'upgrade',
            'amount' => '88.80',
            'discount' => '0.00',
            'paid_amount' => '88.80',
            'billing_cycle' => 'monthly',
            'config_snapshot' => ['upgrade_product_id' => 5566],
            'config_pricing_snapshot' => [
                'meta' => [
                    'kind' => 'host_upgrade',
                    'product_id' => 5566,
                    'billing_cycle' => 'monthly',
                    'promo_code' => 'PROMO88',
                ],
            ],
            'coupon_snapshot' => [],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'host-upgrade-paid-'.$suffix,
        ]);

        $catalog = new class
        {
            public int $checkoutCalls = 0;

            public function previewHostUpgrade($supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => []];
            }

            public function applyHostUpgradePromoCode($supplier, int $hostId, string $promoCode, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => []];
            }

            public function checkoutHostUpgrade($supplier, int $hostId, ?string $jwt = null): array
            {
                $this->checkoutCalls++;

                return ['status' => 200, 'data' => ['invoiceid' => 9988]];
            }

            public function post($supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
            {
                return ['status' => 200, 'data' => []];
            }

            public function getHostDetail($supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['host' => ['domainstatus' => 'Active']]];
            }
        };

        $detailService = $this->createMock(ServiceDetailService::class);
        $detailService->method('resolveUpstreamContext')->willReturn([
            $catalog,
            $supplier,
            778899,
            'jwt-token',
        ]);
        $detailService->method('assertSuccess');
        $detailService->method('extractPayload')->willReturnCallback(fn (array $response) => $response['data'] ?? $response);
        $detailService->expects($this->once())->method('syncServiceFromRemote');

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

        $serviceUpgradeService = new ServiceUpgradeService(
            $detailService,
            app(InvoiceService::class),
            $operationLogService,
        );

        $resolved = $serviceUpgradeService->processPaidUpgradeOrder($order);
        $resolvedAgain = $serviceUpgradeService->processPaidUpgradeOrder($order->fresh());

        $this->assertInstanceOf(Service::class, $resolved);
        $this->assertInstanceOf(Service::class, $resolvedAgain);
        $this->assertSame(1, $catalog->checkoutCalls);
        $this->assertSame(OrderStatus::COMPLETED, (int) $order->fresh()->status);
        $this->assertSame('host_upgrade', (string) data_get($service->fresh()->provision_data ?? [], 'last_upgrade_kind', ''));
        $this->assertSame(9988, (int) data_get($service->fresh()->provision_data ?? [], 'last_upgrade_invoice_id', 0));
        $this->assertSame(778899, (int) data_get($service->fresh()->provision_data ?? [], 'upstream_host_id', 0));
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, (string) DB::table('service_upstream_bindings')
            ->where('service_id', (int) $service->id)
            ->value('provider_key'));
        $this->assertDatabaseHas('service_runtime_snapshots', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->assertDatabaseHas('service_provision_attempts', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'action' => 'upgrade',
            'attempt_status' => 'success',
            'trace_id' => 'host-upgrade-paid-'.$suffix,
        ]);
        $this->assertSame(1, DB::table('service_provision_attempts')
            ->where('service_id', (int) $service->id)
            ->where('action', 'upgrade')
            ->where('trace_id', 'host-upgrade-paid-'.$suffix)
            ->count());
    }

    public function test_process_paid_upgrade_order_records_failed_attempt(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'host-upgrade-failed-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Upgrade Failed Supplier '.$suffix,
            'code' => 'upgrade-failed-supplier-'.$suffix,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://upgrade-failed-'.$suffix.'.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Upgrade Failed Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Upgrade Failed Service '.$suffix,
            'domain' => 'upgrade-failed-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
        $this->createNormalizedServiceBinding($supplier, $product, $service, 5566, 778899);

        $order = Order::query()->create([
            'order_no' => 'UPGF'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '升级失败订单',
            'product_type_snapshot' => 'vps',
            'service_id' => (int) $service->id,
            'type' => 'upgrade',
            'amount' => '88.80',
            'discount' => '0.00',
            'paid_amount' => '88.80',
            'billing_cycle' => 'monthly',
            'config_snapshot' => ['upgrade_product_id' => 5566],
            'config_pricing_snapshot' => [
                'meta' => [
                    'kind' => 'host_upgrade',
                    'product_id' => 5566,
                    'billing_cycle' => 'monthly',
                    'promo_code' => 'PROMO88',
                ],
            ],
            'coupon_snapshot' => [],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'host-upgrade-failed-'.$suffix,
        ]);

        $detailService = $this->createMock(ServiceDetailService::class);
        $detailService->method('resolveUpstreamContext')->willReturn([
            new class
            {
                public function purchaseHostUpgrade(
                    Supplier $supplier,
                    int $hostId,
                    int $productId,
                    string $billingCycle,
                    string $promoCode,
                    ?string $jwt = null
                ): array {
                    throw new BusinessException('上游升降级余额不足');
                }
            },
            $supplier,
            778899,
            'jwt-token',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('writeServiceConsoleLog');

        $serviceUpgradeService = new ServiceUpgradeService(
            $detailService,
            app(InvoiceService::class),
            $operationLogService,
        );

        $resolved = $serviceUpgradeService->processPaidUpgradeOrder($order);

        $this->assertInstanceOf(Service::class, $resolved);
        $this->assertSame(OrderStatus::PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('上游升降级余额不足', (string) data_get($service->fresh()->provision_data ?? [], 'upgrade_error', ''));
        $this->assertDatabaseHas('service_provision_attempts', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'action' => 'upgrade',
            'attempt_status' => 'failed',
            'trace_id' => 'host-upgrade-failed-'.$suffix,
            'error_code' => 'upgrade_failed',
        ]);
    }

    private function createNormalizedServiceBinding(
        Supplier $supplier,
        Product $product,
        Service $service,
        int $upstreamProductId,
        int $upstreamHostId
    ): void {
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) $upstreamProductId,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => (string) $upstreamHostId,
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
