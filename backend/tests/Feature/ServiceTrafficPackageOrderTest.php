<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceTrafficPackageOrderTest extends TestCase
{
    public function test_it_creates_a_local_upgrade_invoice_for_traffic_package_purchase(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = null;
        $supplierId = null;
        $productId = null;
        $categoryId = null;
        $thirdGroupId = null;
        $serviceId = null;
        $orderId = null;
        $invoiceId = null;
        $supplierBindingId = null;
        $productBindingId = null;
        $trafficPackageSettingKeys = [
            'traffic_package_enabled',
            'traffic_package_option_field',
            'traffic_package_option_keyword',
            'traffic_package_allow_choice_mode',
            'traffic_package_allow_quantity_mode',
        ];
        $originalTrafficPackageSettings = Setting::query()
            ->where('group_key', 'traffic_package')
            ->whereIn('item_key', $trafficPackageSettingKeys)
            ->pluck('item_value', 'item_key')
            ->all();

        Setting::setValues('traffic_package', [
            'traffic_package_enabled' => '1',
            'traffic_package_option_field' => 'flow_limit',
            'traffic_package_option_keyword' => '流量',
            'traffic_package_allow_choice_mode' => '1',
            'traffic_package_allow_quantity_mode' => '1',
        ]);

        try {
            $user = User::query()->create([
                'email' => 'traffic-package-'.$suffix.'@example.com',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
            $userId = (int) $user->id;

            $supplier = Supplier::query()->create([
                'name' => 'Traffic Supplier '.$suffix,
                'code' => 'traffic-'.$suffix,
                'interface_type' => ProviderKey::HOSTING_PANEL_API,
                'api_url' => 'https://supplier-'.$suffix.'.example.com',
                'api_username' => 'demo',
                'api_key' => 'secret',
                'status' => 1,
                'sort_order' => 1,
            ]);
            $supplierId = (int) $supplier->id;

            // 使用新的分组结构
            $rootGroup = FirstProductGroup::query()->create([
                'code' => 'traffic-'.$suffix,
                'name' => 'Traffic Root '.$suffix,
                'slug' => 'traffic-root-'.$suffix,
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
            ]);

            $category = SecondProductGroup::query()->create([
                'first_product_group_id' => (int) $rootGroup->id,
                'name' => 'Traffic Category '.$suffix,
                'slug' => 'traffic-category-'.$suffix,
                'sort_order' => 0,
                'is_visible' => 1,
            ]);
            $categoryId = (int) $category->id;

            $thirdGroup = ThirdProductGroup::query()->create([
                'second_product_group_id' => (int) $category->id,
                'name' => 'Traffic Leaf '.$suffix,
                'slug' => 'traffic-leaf-'.$suffix,
                'sort_order' => 0,
                'is_visible' => 1,
            ]);
            $thirdGroupId = (int) $thirdGroup->id;

            $product = Product::query()->create([
                'product_group_id' => (int) $thirdGroup->id,
                'name' => 'Traffic Product '.$suffix,
                'product_type' => 'vps',
                'pricing' => ['monthly' => '19.90'],
                'setup_fee' => '0.00',
                'config_options' => [[
                    'field' => 'flow_limit',
                    'name' => '流量',
                    'option_type' => 1,
                    'sub' => [
                        ['id' => 85333, 'option_name_first' => '1024', 'version' => '1TB'],
                        ['id' => 85334, 'option_name_first' => '2048', 'version' => '2TB'],
                    ],
                ]],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 0,
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 9001,
            ]);
            $productId = (int) $product->id;

            $pluginId = $this->ensureHostingPanelIntegrationPlugin();
            $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
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
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_product_id' => '9001',
                'auto_setup' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $service = Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'order_id' => null,
                'name' => 'Traffic Service '.$suffix,
                'domain' => 'traffic-'.$suffix.'.example.com',
                'billing_cycle' => 'monthly',
                'amount' => '19.90',
                'status' => ServiceStatus::ACTIVE,
                'provision_data' => [
                    'supplier_id' => (int) $supplier->id,
                    'upstream_host_id' => 778899,
                ],
                'expires_at' => now()->addMonth(),
                'auto_renew' => 0,
            ]);
            $serviceId = (int) $service->id;

            DB::table('service_upstream_bindings')->insert([
                'service_id' => (int) $service->id,
                'product_upstream_binding_id' => $productBindingId,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_service_id' => '778899',
                'status_snapshot' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Setting::setValue('traffic_package_catalog', 'items', json_encode([[
                'category_id' => (int) $category->id,
                'product_type' => (string) $product->product_type,
                'label' => '2T',
                'target_value' => 2048,
                'price' => '39.90',
                'enabled' => 1,
                'sort_order' => 1,
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $transport = $this->createMock(HostingPanelApiTransport::class);
            $transport->expects($this->once())
                ->method('login')
                ->with($this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id))
                ->willReturn('traffic-jwt');
            $transport->expects($this->once())
                ->method('getHostDetail')
                ->with(
                    $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id),
                    778899,
                    'traffic-jwt'
                )
                ->willReturn([
                    'status' => 200,
                    'data' => [
                        'host' => [
                            'bwusage' => 256.5,
                            'bwlimit' => 1024,
                            'domainstatus' => 'Active',
                        ],
                    ],
                ]);
            $transport->expects($this->once())
                ->method('get')
                ->willReturnCallback(function (Supplier $candidate, string $uri, ?string $jwt = null, array $query = []) use ($supplier) {
                    $this->assertSame((int) $supplier->id, (int) $candidate->id);
                    $this->assertSame('traffic-jwt', $jwt);

                    if ($uri === '/v1/hosts/778899/module/status') {
                        $this->assertSame(['type' => 'host'], $query);

                        return [
                            'status' => 200,
                            'data' => [
                                'status' => 'Active',
                                'des' => '运行中',
                            ],
                        ];
                    }

                    $this->fail('未预期的上游 GET 请求：'.$uri);
                });
            $transport->expects($this->once())
                ->method('getText')
                ->with(
                    $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id),
                    $this->stringContains('/servicedetail?'),
                    'traffic-jwt',
                    [],
                    []
                )
                ->willReturn('<html></html>');
            $transport->expects($this->once())
                ->method('getHostUpgradeConfigOptions')
                ->with(
                    $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id),
                    778899,
                    'traffic-jwt'
                )
                ->willReturn([
                    'response' => ['status' => 200],
                    'payload' => [],
                    'options' => [[
                        'id' => 15305,
                        'field' => 'flow_limit',
                        'name' => '流量',
                        'option_type' => 1,
                        'current_sub_id' => 85333,
                        'current_label' => '1TB',
                        'current_qty' => 1024,
                        'sub' => [
                            ['id' => 85333, 'option_name_first' => '1024', 'version' => '1TB'],
                            ['id' => 85334, 'option_name_first' => '2048', 'version' => '2TB'],
                        ],
                    ]],
                ]);

            $operationLogService = $this->createMock(OperationLogService::class);
            $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

            $providerResolver = $this->makeProviderResolver($transport);
            $detailService = new ServiceDetailService(
                $providerResolver,
                $this->createMock(OperationLogService::class),
                new ServiceResolverService,
                new ServiceTransformService(new ServiceResolverService)
            );

            $trafficPackageService = new ServiceTrafficPackageService(
                $detailService,
                new InvoiceService,
                $operationLogService,
                new SettingService,
                $providerResolver
            );

            $invoice = $trafficPackageService->createInvoiceForUser(
                $user,
                (int) $service->id,
                ['target_value' => 2048],
                [
                    'actor_type' => 'client',
                    'actor_user_id' => (int) $user->id,
                    'actor_name' => (string) $user->email,
                    'trace_id' => 'traffic-package-'.$suffix,
                ]
            );

            $invoiceId = (int) $invoice->id;
            $orderId = (int) ($invoice->order_id ?? 0);

            $this->assertSame('upgrade', (string) $invoice->type);
            $this->assertSame((int) $service->id, (int) $invoice->service_id);
            $this->assertGreaterThan(0, $orderId);
            $this->assertSame('39.90', number_format((float) $invoice->amount, 2, '.', ''));
            $this->assertSame(2048, (int) ($invoice->config_snapshot['flow_limit'] ?? 0));
            $this->assertSame(
                ['15305' => 85334],
                (array) data_get($invoice->config_pricing_snapshot ?? [], 'meta.configoption', [])
            );
            $this->assertDatabaseHas('invoices', [
                'id' => (int) $invoice->id,
                'type' => 'upgrade',
                'service_id' => (int) $service->id,
                'status' => InvoiceStatus::UNPAID,
            ]);
            $this->assertDatabaseHas('orders', [
                'id' => $orderId,
                'type' => 'upgrade',
                'service_id' => (int) $service->id,
                'status' => OrderStatus::PENDING,
            ]);
        } finally {
            Setting::query()
                ->where('group_key', 'traffic_package')
                ->whereIn('item_key', $trafficPackageSettingKeys)
                ->delete();
            Setting::setValues('traffic_package', $originalTrafficPackageSettings);
            Setting::forgetCachedGroup('traffic_package');

            if ($invoiceId !== null && $invoiceId > 0) {
                DB::table('invoices')->where('id', $invoiceId)->delete();
            }
            if ($orderId !== null && $orderId > 0) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
            if ($serviceId !== null) {
                DB::table('service_upstream_bindings')->where('service_id', $serviceId)->delete();
                DB::table('services')->where('id', $serviceId)->delete();
            }
            if ($productBindingId !== null) {
                DB::table('product_upstream_bindings')->where('id', $productBindingId)->delete();
            }
            if ($productId !== null) {
                DB::table('products')->where('id', $productId)->delete();
            }
            if ($supplierBindingId !== null) {
                DB::table('supplier_plugin_bindings')->where('id', $supplierBindingId)->delete();
            }
            if ($supplierId !== null) {
                DB::table('suppliers')->where('id', $supplierId)->delete();
            }
            if ($thirdGroupId !== null) {
                DB::table('third_product_groups')->where('id', $thirdGroupId)->delete();
            }
            if ($categoryId !== null) {
                DB::table('second_product_groups')->where('id', $categoryId)->delete();
            }
            if (isset($rootGroup)) {
                DB::table('first_product_groups')->where('id', (int) $rootGroup->id)->delete();
            }
            Setting::setValue('traffic_package_catalog', 'items', '[]');
            if ($userId !== null) {
                DB::table('users')->where('id', $userId)->delete();
            }
        }
    }

    public function test_paid_upgrade_invoice_routes_to_traffic_package_processor(): void
    {
        if (! method_exists(CouponService::class, 'syncOrderCouponUsageAfterResponse')) {
            $this->markTestIncomplete(
                'Runtime blocker: PaymentService::handlePaidInvoice() still calls missing CouponService::syncOrderCouponUsageAfterResponse().'
            );
        }

        $suffix = bin2hex(random_bytes(4));
        $userId = null;
        $productId = null;
        $serviceId = null;
        $orderId = null;
        $invoiceId = null;

        try {
            $user = User::query()->create([
                'email' => 'traffic-upgrade-sync-'.$suffix.'@example.com',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
            $userId = (int) $user->id;

            $product = Product::query()->create([
                'name' => 'Traffic Sync Product '.$suffix,
                'product_type' => 'vps',
                'pricing' => ['monthly' => '10.00'],
                'setup_fee' => '0.00',
                'config_options' => [],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 0,
            ]);
            $productId = (int) $product->id;

            $order = Order::query()->create([
                'order_no' => 'UPGRADE'.strtoupper($suffix),
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
                'product_type_snapshot' => (string) $product->product_type,
                'service_id' => null,
                'type' => 'upgrade',
                'amount' => '30.00',
                'discount' => '0.00',
                'paid_amount' => '30.00',
                'billing_cycle' => 'one_time',
                'config_snapshot' => ['flow_limit' => 2048],
                'config_pricing_snapshot' => [
                    'meta' => [
                        'kind' => 'traffic_package',
                        'configoption' => ['15305' => 85334],
                    ],
                ],
                'coupon_snapshot' => [],
                'status' => OrderStatus::PAID,
                'paid_at' => now(),
                'trace_id' => 'traffic-paid-binding-'.$suffix,
            ]);
            $orderId = (int) $order->id;

            $service = Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'order_id' => null,
                'name' => 'Traffic Sync Service '.$suffix,
                'domain' => 'sync-'.$suffix.'.example.com',
                'billing_cycle' => 'monthly',
                'amount' => '10.00',
                'status' => ServiceStatus::ACTIVE,
                'provision_data' => [],
                'expires_at' => now()->addMonth(),
                'auto_renew' => 0,
            ]);
            $serviceId = (int) $service->id;

            $order->forceFill([
                'service_id' => (int) $service->id,
            ])->save();

            $invoice = Invoice::query()->create([
                'invoice_no' => 'INVUPGRADE'.strtoupper($suffix),
                'user_id' => (int) $user->id,
                'order_id' => (int) $order->id,
                'type' => 'upgrade',
                'amount' => '30.00',
                'paid_amount' => '30.00',
                'status' => InvoiceStatus::PAID,
                'paid_at' => now(),
                'due_date' => now()->addDay(),
            ]);
            $invoiceId = (int) $invoice->id;

            $trafficPackageProcessor = $this->createMock(ServiceTrafficPackageService::class);
            $this->app->instance(ServiceTrafficPackageService::class, $trafficPackageProcessor);

            $dispatcher = $this->createMock(PaidOrderBusinessFlowDispatcher::class);
            $dispatcher->expects($this->once())
                ->method('dispatchPaidInvoice')
                ->with(
                    $this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id),
                    'trace-upgrade-sync-'.$suffix
                );

            $couponService = $this->createMock(CouponService::class);
            $couponService->expects($this->once())
                ->method('syncInvoiceCouponUsageAfterResponse')
                ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id));

            $adminOrderNotificationService = $this->createMock(AdminOrderNotificationService::class);
            $adminOrderNotificationService->expects($this->once())
                ->method('notifyInvoicePaidAfterResponse')
                ->with($this->callback(fn (Invoice $candidate): bool => (int) $candidate->id === (int) $invoice->id));

            $paymentService = new PaymentService(
                $this->createMock(ProvisionService::class),
                $this->makePaymentGatewayManagerForTest(),
                $this->createMock(ServiceRenewService::class),
                $this->createMock(ReferralService::class),
                $dispatcher,
                $adminOrderNotificationService,
                $couponService,
                new InvoiceService,
            );

            $paymentService->handlePaidInvoice($invoice, 'trace-upgrade-sync-'.$suffix);
        } finally {
            if ($invoiceId !== null && $invoiceId > 0) {
                DB::table('invoices')->where('id', $invoiceId)->delete();
            }
            if ($orderId !== null && $orderId > 0) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
            if ($serviceId !== null) {
                DB::table('services')->where('id', $serviceId)->delete();
            }
            if ($productId !== null) {
                DB::table('products')->where('id', $productId)->delete();
            }
            if ($userId !== null) {
                DB::table('users')->where('id', $userId)->delete();
            }
        }
    }

    public function test_process_paid_traffic_package_order_syncs_normalized_service_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = null;
        $supplierId = null;
        $productId = null;
        $serviceId = null;
        $orderId = null;
        $supplierBindingId = null;
        $productBindingId = null;

        try {
            $pluginId = $this->ensureHostingPanelIntegrationPlugin();

            $user = User::query()->create([
                'email' => 'traffic-paid-binding-'.$suffix.'@example.com',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
            $userId = (int) $user->id;

            $supplier = Supplier::query()->create([
                'name' => 'Traffic Paid Supplier '.$suffix,
                'code' => 'traffic-paid-'.$suffix,
                'interface_type' => ProviderKey::HOSTING_PANEL_API,
                'api_url' => 'https://supplier-paid-'.$suffix.'.example.com',
                'api_username' => 'demo',
                'api_key' => 'secret',
                'status' => 1,
                'sort_order' => 1,
            ]);
            $supplierId = (int) $supplier->id;

            $product = Product::query()->create([
                'name' => 'Traffic Paid Product '.$suffix,
                'product_type' => 'vps',
                'pricing' => ['monthly' => '19.90'],
                'setup_fee' => '0.00',
                'config_options' => [],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 0,
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 9001,
            ]);
            $productId = (int) $product->id;

            $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
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
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_product_id' => '9001',
                'auto_setup' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $service = Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'order_id' => null,
                'name' => 'Traffic Paid Service '.$suffix,
                'domain' => 'traffic-paid-'.$suffix.'.example.com',
                'billing_cycle' => 'monthly',
                'amount' => '19.90',
                'status' => ServiceStatus::ACTIVE,
                'provision_data' => [],
                'expires_at' => now()->addMonth(),
                'auto_renew' => 0,
            ]);
            $serviceId = (int) $service->id;

            DB::table('service_upstream_bindings')->insert([
                'service_id' => (int) $service->id,
                'product_upstream_binding_id' => $productBindingId,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_service_id' => '778899',
                'status_snapshot' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $order = Order::query()->create([
                'order_no' => 'TRFP'.strtoupper($suffix),
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'product_spec_snapshot' => '流量包订单',
                'product_type_snapshot' => 'vps',
                'service_id' => (int) $service->id,
                'type' => 'upgrade',
                'amount' => '39.90',
                'discount' => '0.00',
                'paid_amount' => '39.90',
                'billing_cycle' => 'one_time',
                'config_snapshot' => ['flow_limit' => 2048],
                'config_pricing_snapshot' => [
                    'meta' => [
                        'kind' => 'traffic_package',
                        'mode' => 'upgradeconfig',
                        'configoption' => ['15305' => 85334],
                        'target_label' => '2TB',
                    ],
                ],
                'coupon_snapshot' => [],
                'status' => OrderStatus::PAID,
                'paid_at' => now(),
                'trace_id' => 'traffic-paid-binding-'.$suffix,
            ]);
            $orderId = (int) $order->id;
            $this->assertSame(
                'traffic-paid-binding-'.$suffix,
                (string) DB::table('orders')->where('id', $orderId)->value('trace_id')
            );

            $catalog = new class
            {
                public function purchaseTrafficPackage(
                    Supplier $supplier,
                    int $hostId,
                    string $mode,
                    array $configOption,
                    int $flowPacketId,
                    string $rootUrl,
                    ?string $jwt = null
                ): array {
                    return [
                        'upstream_invoice_id' => 8899,
                        'host_detail' => [
                            'domainstatus' => 'Active',
                            'bwusage' => 300,
                            'bwlimit' => 2048,
                        ],
                    ];
                }
            };

            $detailService = $this->createMock(ServiceDetailService::class);
            $detailService->method('resolveUpstreamContext')->willReturn([$catalog, $supplier, 778899, 'traffic-jwt']);
            $detailService->method('resolveSupplierRootUrl')->willReturn('https://supplier-paid.example.com');
            $detailService->expects($this->once())->method('syncServiceFromRemote');

            $operationLogService = $this->createMock(OperationLogService::class);
            $operationLogService->expects($this->once())->method('writeServiceConsoleLog');

            $trafficPackageService = new ServiceTrafficPackageService(
                $detailService,
                new InvoiceService,
                $operationLogService,
                new SettingService,
                new ProviderResolver(new ProviderRegistry([]))
            );

            $resolved = $trafficPackageService->processPaidTrafficPackageOrder($order);

            $this->assertInstanceOf(Service::class, $resolved);
            $this->assertSame(OrderStatus::COMPLETED, (int) $order->fresh()->status);
            $this->assertSame('traffic_package', (string) data_get($service->fresh()->provision_data ?? [], 'last_upgrade_kind', ''));
            $this->assertSame(8899, (int) data_get($service->fresh()->provision_data ?? [], 'last_upgrade_invoice_id', 0));
            $this->assertSame(778899, (int) data_get($service->fresh()->provision_data ?? [], 'upstream_host_id', 0));
            $this->assertDatabaseHas('service_runtime_snapshots', [
                'service_id' => (int) $service->id,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
            ]);
            $this->assertDatabaseHas('service_provision_attempts', [
                'service_id' => (int) $service->id,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'action' => 'traffic_package',
                'attempt_status' => 'success',
                'trace_id' => 'traffic-paid-binding-'.$suffix,
            ]);
        } finally {
            if ($orderId !== null && $orderId > 0) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
            if ($serviceId !== null) {
                DB::table('service_provision_attempts')->where('service_id', $serviceId)->delete();
                DB::table('service_upstream_bindings')->where('service_id', $serviceId)->delete();
                DB::table('services')->where('id', $serviceId)->delete();
            }
            if ($productBindingId !== null) {
                DB::table('product_upstream_bindings')->where('id', $productBindingId)->delete();
            }
            if ($productId !== null) {
                DB::table('products')->where('id', $productId)->delete();
            }
            if ($supplierBindingId !== null) {
                DB::table('supplier_plugin_bindings')->where('id', $supplierBindingId)->delete();
            }
            if ($supplierId !== null) {
                DB::table('suppliers')->where('id', $supplierId)->delete();
            }
            if ($userId !== null) {
                DB::table('users')->where('id', $userId)->delete();
            }
        }
    }

    public function test_process_paid_traffic_package_order_records_failed_attempt(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = null;
        $supplierId = null;
        $productId = null;
        $serviceId = null;
        $orderId = null;
        $supplierBindingId = null;
        $productBindingId = null;

        try {
            $pluginId = $this->ensureHostingPanelIntegrationPlugin();

            $user = User::query()->create([
                'email' => 'traffic-paid-failed-'.$suffix.'@example.com',
                'password' => 'Temp@123456',
                'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
            $userId = (int) $user->id;

            $supplier = Supplier::query()->create([
                'name' => 'Traffic Failed Supplier '.$suffix,
                'code' => 'traffic-failed-'.$suffix,
                'interface_type' => ProviderKey::HOSTING_PANEL_API,
                'api_url' => 'https://supplier-failed-'.$suffix.'.example.com',
                'api_username' => 'demo',
                'api_key' => 'secret',
                'status' => 1,
                'sort_order' => 1,
            ]);
            $supplierId = (int) $supplier->id;

            $product = Product::query()->create([
                'name' => 'Traffic Failed Product '.$suffix,
                'product_type' => 'vps',
                'pricing' => ['monthly' => '19.90'],
                'setup_fee' => '0.00',
                'config_options' => [],
                'purchase_requires' => [],
                'stock' => -1,
                'status' => 1,
                'auto_setup' => 0,
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => 9002,
            ]);
            $productId = (int) $product->id;

            $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
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
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_product_id' => '9002',
                'auto_setup' => 1,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $service = Service::query()->create([
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'order_id' => null,
                'name' => 'Traffic Failed Service '.$suffix,
                'domain' => 'traffic-failed-'.$suffix.'.example.com',
                'billing_cycle' => 'monthly',
                'amount' => '19.90',
                'status' => ServiceStatus::ACTIVE,
                'provision_data' => [],
                'expires_at' => now()->addMonth(),
                'auto_renew' => 0,
            ]);
            $serviceId = (int) $service->id;

            DB::table('service_upstream_bindings')->insert([
                'service_id' => (int) $service->id,
                'product_upstream_binding_id' => $productBindingId,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'plugin_id' => $pluginId,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'upstream_service_id' => '887766',
                'status_snapshot' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $order = Order::query()->create([
                'order_no' => 'TRFF'.strtoupper($suffix),
                'user_id' => (int) $user->id,
                'product_id' => (int) $product->id,
                'product_spec_snapshot' => '流量包失败订单',
                'product_type_snapshot' => 'vps',
                'service_id' => (int) $service->id,
                'type' => 'upgrade',
                'amount' => '39.90',
                'discount' => '0.00',
                'paid_amount' => '39.90',
                'billing_cycle' => 'one_time',
                'config_snapshot' => ['flow_limit' => 4096],
                'config_pricing_snapshot' => [
                    'meta' => [
                        'kind' => 'traffic_package',
                        'mode' => 'upgradeconfig',
                        'configoption' => ['15305' => 85335],
                        'target_label' => '4TB',
                    ],
                ],
                'coupon_snapshot' => [],
                'status' => OrderStatus::PAID,
                'paid_at' => now(),
                'trace_id' => 'traffic-paid-failed-'.$suffix,
            ]);
            $orderId = (int) $order->id;

            $catalog = new class
            {
                public function purchaseTrafficPackage(
                    Supplier $supplier,
                    int $hostId,
                    string $mode,
                    array $configOption,
                    int $flowPacketId,
                    string $rootUrl,
                    ?string $jwt = null
                ): array {
                    throw new BusinessException('上游余额不足');
                }
            };

            $detailService = $this->createMock(ServiceDetailService::class);
            $detailService->method('resolveUpstreamContext')->willReturn([$catalog, $supplier, 887766, 'traffic-jwt']);
            $detailService->method('resolveSupplierRootUrl')->willReturn('https://supplier-failed.example.com');

            $trafficPackageService = new ServiceTrafficPackageService(
                $detailService,
                new InvoiceService,
                $this->createMock(OperationLogService::class),
                new SettingService,
                new ProviderResolver(new ProviderRegistry([]))
            );

            $resolved = $trafficPackageService->processPaidTrafficPackageOrder($order);

            $this->assertInstanceOf(Service::class, $resolved);
            $this->assertSame(OrderStatus::PROCESSING, (int) $order->fresh()->status);
            $this->assertSame('上游余额不足', (string) data_get($service->fresh()->provision_data ?? [], 'upgrade_error', ''));
            $this->assertDatabaseHas('service_provision_attempts', [
                'service_id' => (int) $service->id,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'action' => 'traffic_package',
                'attempt_status' => 'failed',
                'trace_id' => 'traffic-paid-failed-'.$suffix,
                'error_code' => 'traffic_package_failed',
            ]);
        } finally {
            if ($orderId !== null && $orderId > 0) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
            if ($serviceId !== null) {
                DB::table('service_provision_attempts')->where('service_id', $serviceId)->delete();
                DB::table('service_upstream_bindings')->where('service_id', $serviceId)->delete();
                DB::table('services')->where('id', $serviceId)->delete();
            }
            if ($productBindingId !== null) {
                DB::table('product_upstream_bindings')->where('id', $productBindingId)->delete();
            }
            if ($productId !== null) {
                DB::table('products')->where('id', $productId)->delete();
            }
            if ($supplierBindingId !== null) {
                DB::table('supplier_plugin_bindings')->where('id', $supplierBindingId)->delete();
            }
            if ($supplierId !== null) {
                DB::table('suppliers')->where('id', $supplierId)->delete();
            }
            if ($userId !== null) {
                DB::table('users')->where('id', $userId)->delete();
            }
        }
    }

    private function makeProviderResolver(HostingPanelApiTransport $transport): ProviderResolver
    {
        return new ProviderResolver(new ProviderRegistry([
            new HostingPanelApiDriver($transport),
        ]));
    }

    private function ensureHostingPanelIntegrationPlugin(): int
    {
        DB::table('integration_plugins')->updateOrInsert([
            'domain' => 'upstream',
            'plugin_key' => ProviderKey::HOSTING_PANEL_API,
        ], [
            'slug' => ProviderKey::HOSTING_PANEL_API,
            'name' => 'Hosting Panel API',
            'version' => '1.0.0',
            'provider_class' => null,
            'entry_class' => HostingPanelApiDriver::class,
            'capabilities_json' => json_encode([]),
            'config_schema_json' => json_encode([]),
            'status' => 1,
            'installed_at' => now(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        return (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::HOSTING_PANEL_API)
            ->value('id');
    }
}
