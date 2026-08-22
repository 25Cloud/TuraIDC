<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\ServiceUpstreamBindingWriter;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\ProviderResolver;
use App\Support\OrderInvoiceNoGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ServiceTrafficPackageService
{
    private const TRAFFIC_ORDER_KIND = 'traffic_package';

    private const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    public function __construct(
        private readonly ServiceDetailService $detailService,
        private readonly InvoiceService $invoiceService,
        private readonly OperationLogService $operationLogService,
        private readonly SettingService $settingService,
        private readonly ProviderResolver $providerResolver,
        private readonly ?PluginBindingResolver $bindingResolver = null,
        private ?ServiceUpstreamBindingWriter $serviceBindingWriter = null,
    ) {}

    public function previewForUser(User $user, int $serviceId): array
    {
        $service = $this->findManagedService($user, $serviceId);

        try {
            $context = $this->loadTrafficPackageContext($service);
        } catch (BusinessException $exception) {
            return [
                'supported' => false,
                'message' => $exception->getMessage(),
                'service_id' => (int) $service->id,
                'service_name' => (string) $service->name,
            ];
        }

        return [
            'supported' => true,
            'message' => '',
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'traffic' => $this->buildTrafficUsagePayload($context['host']),
            'packages' => $context['packages'],
        ];
    }

    public function quoteForUser(User $user, int $serviceId, array $selection): array
    {
        $service = $this->findManagedService($user, $serviceId);

        return $this->quoteForService($service, $selection);
    }

    public function pullCatalogTemplateForAdmin(
        int $secondProductGroupId,
        ?int $thirdProductGroupId = null,
        string $productType = '',
        int $supplierId = 0,
        int $upstreamProductId = 0,
        int $sourceProductId = 0,
    ): array {
        throw_if($secondProductGroupId <= 0, new BusinessException('请选择有效的商品分类'));

        if ($sourceProductId > 0) {
            return $this->pullCatalogTemplateFromLocalProduct($sourceProductId);
        }

        if ($supplierId > 0 && $upstreamProductId > 0) {
            return $this->pullCatalogTemplateFromSupplierProduct($supplierId, $upstreamProductId);
        }

        try {
            [$service, $supplier, $hostId, $jwt] = $this->resolveAdminImportSource($secondProductGroupId, $thirdProductGroupId, $productType);
            $catalog = $this->resolveCatalogCapability($supplier);

            $host = [];
            $flowPacketPackages = $this->tryPullFlowPacketFromHtml($supplier, $hostId, $jwt);
            if ($flowPacketPackages !== []) {
                try {
                    $detailResponse = $catalog->getHostDetail($supplier, $hostId, $jwt);
                    $this->detailService->assertSuccess($detailResponse, '读取实例流量信息');
                    $detailPayload = $this->detailService->extractPayload($detailResponse);
                    $host = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                } catch (\Throwable) {
                    $host = [];
                }

                return [
                    'source' => [
                        'mode' => 'flowpacket',
                        'service_id' => (int) $service->id,
                        'service_name' => (string) $service->name,
                        'product_id' => (int) ($service->product_id ?? 0),
                        'product_name' => (string) ($service->product?->name ?? ''),
                        'supplier_id' => (int) $supplier->id,
                        'supplier_name' => (string) ($supplier->name ?? ''),
                        'upstream_host_id' => $hostId,
                    ],
                    'traffic' => $this->buildTrafficUsagePayload($host),
                    'packages' => $flowPacketPackages,
                ];
            }

            $detailResponse = $catalog->getHostDetail($supplier, $hostId, $jwt);
            $this->detailService->assertSuccess($detailResponse, '读取实例流量信息');
            $detailPayload = $this->detailService->extractPayload($detailResponse);
            $host = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];

            // flowpacket 页面未返回数据，回退到 upgradeconfig API
            $config = $this->settingService->getTrafficPackageConfig();
            $upgradeConfig = $catalog->getHostUpgradeConfigOptions($supplier, $hostId, $jwt);
            $this->detailService->assertSuccess($upgradeConfig['response'], '读取流量包档位');
            $trafficOption = $this->findTrafficPackageOption($upgradeConfig['options'], $config);

            throw_if(! is_array($trafficOption), new BusinessException('当前分类样本实例未返回可用的流量包配置项'));
            throw_if($this->isRangeTrafficOption($trafficOption), new BusinessException('当前上游流量包为数量区间模式，暂不支持一键拉取为固定档位，请手动维护价格'));

            $packages = $this->pullPackagesFromUpstreamOption($supplier, $hostId, $jwt, $trafficOption, $host);
            throw_if($packages === [], new BusinessException('未从上游解析到可导入的流量包档位'));

            return [
                'source' => [
                    'mode' => 'service',
                    'service_id' => (int) $service->id,
                    'service_name' => (string) $service->name,
                    'product_id' => (int) ($service->product_id ?? 0),
                    'product_name' => (string) ($service->product?->name ?? ''),
                    'supplier_id' => (int) $supplier->id,
                    'supplier_name' => (string) ($supplier->name ?? ''),
                    'upstream_host_id' => $hostId,
                ],
                'traffic' => $this->buildTrafficUsagePayload($host),
                'packages' => $packages,
            ];
        } catch (BusinessException $exception) {
            return $this->pullCatalogTemplateFromProduct($secondProductGroupId, $thirdProductGroupId, $productType, $exception->getMessage());
        }
    }

    public function createInvoiceForUser(User $user, int $serviceId, array $selection, array $context = []): Invoice
    {
        $service = $this->findManagedService($user, $serviceId);
        $quote = $this->quoteForService($service, $selection);
        $product = $service->product;

        throw_if(! $product, new BusinessException('服务关联商品不存在，无法创建流量包账单'));

        $existingInvoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->where('service_id', (int) $service->id)
            ->where('type', OrderType::UPGRADE)
            ->where('status', InvoiceStatus::UNPAID)
            ->latest('id')
            ->first();

        if ($existingInvoice instanceof Invoice) {
            $sameConfigOption = (array) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.configoption', []) === $quote['configoption'];
            $sameKind = (string) data_get($existingInvoice->config_pricing_snapshot ?? [], 'meta.kind', '') === self::TRAFFIC_ORDER_KIND;
            $sameAmount = round((float) ($existingInvoice->amount ?? 0), 2) === round((float) $quote['pricing']['amount'], 2);

            if ($sameKind && $sameConfigOption && $sameAmount) {
                $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($product, (array) ($existingInvoice->config_snapshot ?? []));
                $productSpecDisplay = (string) ($displayPayload['product_spec_display'] ?? $displayPayload['combined_display_name'] ?? '');
                $this->ensureTrafficPackageOrderForInvoice($existingInvoice, $service, $product, $productSpecDisplay, $context);
                $existingInvoice = $existingInvoice->fresh(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service', 'order']) ?? $existingInvoice;

                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.traffic_package.invoice.create', [
                    'category' => 'upgrade',
                    'summary' => '获取待支付流量包账单',
                    'amount' => (string) $quote['pricing']['amount'],
                    'invoice_id' => (int) $existingInvoice->id,
                    'invoice_no' => (string) $existingInvoice->invoice_no,
                    'traffic_label' => (string) $quote['selection']['target_label'],
                    'reused_invoice' => true,
                ], $context);

                return $existingInvoice;
            }

            app(CheckoutService::class)->cancel($existingInvoice, array_merge($context, [
                'actor_type' => (string) ($context['actor_type'] ?? 'system'),
                'actor_user_id' => (int) ($context['actor_user_id'] ?? $user->id),
                'actor_name' => (string) ($context['actor_name'] ?? ($user->display_name ?? $user->nickname ?? $user->email ?? '')),
                'reason' => 'traffic_package_invoice_replaced',
            ]));
        }

        $invoice = DB::transaction(function () use ($service, $product, $quote, $context) {
            $displayPayload = (new ProductDisplayNameResolver)->resolveForProduct($product);
            $productSpecDisplay = (string) ($displayPayload['product_spec_display'] ?? $displayPayload['combined_display_name'] ?? '');
            $orderNo = Order::generateOrderNo();
            $invoice = Invoice::query()->create([
                'invoice_no' => Invoice::generateInvoiceNoFromOrderNo($orderNo),
                'user_id' => (int) $service->user_id,
                'product_id' => (int) $product->id,
                'product_spec_snapshot' => $productSpecDisplay,
                'product_type_snapshot' => (string) $product->product_type,
                'service_id' => (int) $service->id,
                'type' => OrderType::UPGRADE,
                'amount' => (string) $quote['pricing']['amount'],
                'discount' => '0.00',
                'paid_amount' => '0.00',
                'billing_cycle' => (string) ($quote['pricing']['billing_cycle'] ?: 'one_time'),
                'quantity' => 1,
                'config_snapshot' => [
                    'flow_limit' => $quote['selection']['target_snapshot'],
                ],
                'config_pricing_snapshot' => [
                    'base_amount' => '0.00',
                    'config_amount' => (string) $quote['pricing']['amount'],
                    'setup_fee' => '0.00',
                    'items' => [[
                        'field' => 'flow_limit',
                        'label' => '流量',
                        'value_label' => (string) $quote['selection']['target_label'],
                        'amount' => (string) $quote['pricing']['amount'],
                    ]],
                    'meta' => [
                        'kind' => self::TRAFFIC_ORDER_KIND,
                        'mode' => (string) ($quote['mode'] ?? 'upgradeconfig'),
                        'configoption' => $quote['configoption'],
                        'flow_packet_id' => (int) ($quote['flow_packet_id'] ?? 0),
                        'option_id' => (int) $quote['selection']['option_id'],
                        'target_label' => (string) $quote['selection']['target_label'],
                        'target_snapshot' => $quote['selection']['target_snapshot'],
                        'current_label' => (string) $quote['selection']['current_label'],
                        'service_id' => (int) $service->id,
                        'service_name' => (string) $service->name,
                        'upstream_host_id' => (int) $quote['upstream_host_id'],
                    ],
                ],
                'status' => InvoiceStatus::UNPAID,
                'due_date' => now()->addDays(7),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]);

            $this->invoiceService->syncProjection($invoice);
            $this->createTrafficPackageOrderForInvoice($invoice, $service, $product, $productSpecDisplay, $orderNo);

            return $invoice->load(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service', 'order']);
        });

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.traffic_package.invoice.create', [
            'category' => 'upgrade',
            'summary' => '创建流量包账单',
            'amount' => (string) $quote['pricing']['amount'],
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'traffic_label' => (string) $quote['selection']['target_label'],
            'reused_invoice' => false,
        ], $context);

        return $invoice;
    }

    private function ensureTrafficPackageOrderForInvoice(
        Invoice $invoice,
        Service $service,
        Product $product,
        string $productSpecDisplay,
        array $context = [],
    ): Order {
        $invoice->loadMissing('order');

        if ($invoice->order instanceof Order) {
            return $invoice->order;
        }

        return DB::transaction(function () use ($invoice, $service, $product, $productSpecDisplay, $context): Order {
            $lockedInvoice = Invoice::query()
                ->lockForUpdate()
                ->with('order')
                ->findOrFail((int) $invoice->id);

            if ($lockedInvoice->order instanceof Order) {
                return $lockedInvoice->order;
            }

            $orderNo = OrderInvoiceNoGenerator::deriveOrderNoFromInvoiceNo((string) $lockedInvoice->invoice_no)
                ?? Order::generateOrderNo();

            return $this->createTrafficPackageOrderForInvoice(
                $lockedInvoice,
                $service,
                $product,
                $productSpecDisplay,
                $orderNo,
                $context
            );
        });
    }

    private function createTrafficPackageOrderForInvoice(
        Invoice $invoice,
        Service $service,
        Product $product,
        string $productSpecDisplay,
        ?string $orderNo = null,
        array $context = [],
    ): Order {
        $order = Order::query()->create([
            'order_no' => $orderNo ?: Order::generateOrderNo(),
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $invoice->user_id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => $productSpecDisplay !== '' ? $productSpecDisplay : (string) ($invoice->product_spec_snapshot ?? ''),
            'product_type_snapshot' => (string) $product->product_type,
            'service_id' => (int) $service->id,
            'type' => OrderType::UPGRADE,
            'amount' => (string) $invoice->amount,
            'discount' => (string) ($invoice->discount ?? '0.00'),
            'paid_amount' => (string) ($invoice->paid_amount ?? '0.00'),
            'billing_cycle' => (string) ($invoice->billing_cycle ?: 'one_time'),
            'quantity' => 1,
            'config_snapshot' => (array) ($invoice->config_snapshot ?? []),
            'config_pricing_snapshot' => (array) ($invoice->config_pricing_snapshot ?? []),
            'coupon_snapshot' => (array) ($invoice->coupon_snapshot ?? []),
            'status' => (int) $invoice->status === InvoiceStatus::PAID ? OrderStatus::PAID : OrderStatus::PENDING,
            'paid_at' => $invoice->paid_at,
            'trace_id' => (string) ($context['trace_id'] ?? $invoice->trace_id ?? ''),
        ]);

        $invoice->forceFill(['order_id' => (int) $order->id])->save();

        return $order;
    }

    public function processPaidTrafficPackageOrder(Order $order): ?Service
    {
        $order->loadMissing(['service.product.supplier', 'product.supplier']);
        $service = $order->service;

        if (! $service instanceof Service) {
            return null;
        }

        if ($this->isTrafficPackageOrderAlreadyCompleted($order, $service)) {
            return $service->fresh(['product.supplier', 'order']) ?? $service;
        }

        $meta = (array) data_get($order->config_pricing_snapshot ?? [], 'meta', []);
        $mode = (string) ($meta['mode'] ?? 'upgradeconfig');
        $configOption = (array) ($meta['configoption'] ?? []);
        $flowPacketId = (int) ($meta['flow_packet_id'] ?? 0);

        if ($mode !== 'flowpacket') {
            throw_if($configOption === [], new BusinessException('流量包订单缺少上游配置，无法继续执行'));
        } else {
            throw_if($flowPacketId <= 0, new BusinessException('流量包订单缺少 flow_packet_id，无法继续执行'));
        }

        $attemptRequestMeta = $this->trafficPackageAttemptRequestMeta($order, $mode, $configOption, $flowPacketId);
        $resolvedHostId = 0;

        try {
            [$catalog, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            $resolvedHostId = (int) $hostId;
            $invoiceId = 0;
            $hostDetail = [];

            if (is_callable([$catalog, 'purchaseTrafficPackage'])) {
                $purchaseResult = $catalog->purchaseTrafficPackage(
                    $supplier,
                    $hostId,
                    $mode,
                    $configOption,
                    $flowPacketId,
                    $this->detailService->resolveSupplierRootUrl($supplier),
                    $jwt
                );
                $invoiceId = (int) ($purchaseResult['upstream_invoice_id'] ?? 0);
                $hostDetail = is_array($purchaseResult['host_detail'] ?? null) ? $purchaseResult['host_detail'] : [];
            } else {
                if ($mode === 'flowpacket') {
                    $rootUrl = $this->detailService->resolveSupplierRootUrl($supplier);
                    $buyResponse = $catalog->buyFlowPacket($supplier, $rootUrl, $flowPacketId, $hostId, $jwt);
                    $buyCode = (int) ($buyResponse['code'] ?? -1);
                    throw_if($buyCode !== 0, new BusinessException(
                        '上游流量包购买失败：'.(string) ($buyResponse['msg'] ?? $buyResponse['message'] ?? '未知错误')
                    ));
                    $invoiceId = (int) ($buyResponse['data']['invoiceid'] ?? $buyResponse['invoiceid'] ?? 0);
                } else {
                    $previewResponse = $catalog->previewHostConfigUpgrade($supplier, $hostId, $configOption, $jwt);
                    $this->detailService->assertSuccess($previewResponse, '提交流量包升级配置');

                    $checkoutResponse = $catalog->checkoutHostConfigUpgrade($supplier, $hostId, $jwt);
                    $this->detailService->assertSuccess($checkoutResponse, '生成流量包账单');
                    $checkoutPayload = $this->detailService->extractPayload($checkoutResponse);
                    $invoiceId = (int) ($checkoutPayload['invoiceid'] ?? $checkoutResponse['invoiceid'] ?? 0);

                    throw_if($invoiceId <= 0, new BusinessException('上游未返回流量包账单 ID'));

                    $fundResponse = $catalog->post($supplier, "/v1/invoices/{$invoiceId}/fund", [], $jwt);
                    $this->detailService->assertSuccess($fundResponse, '使用供应商余额支付流量包账单');
                }

                $detailResponse = $catalog->getHostDetail(
                    $supplier,
                    $hostId,
                    $jwt
                );
                $this->detailService->assertSuccess($detailResponse, '读取流量包购买结果');
                $detailPayload = $this->detailService->extractPayload($detailResponse);
                $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
            }

            if ($hostDetail !== []) {
                $this->detailService->syncServiceFromRemote($service, $hostDetail);
                $service->refresh();
            }

            $provisionData = $this->serviceProvisionData($service);
            $provisionData['last_upgrade_kind'] = self::TRAFFIC_ORDER_KIND;
            $provisionData['last_upgrade_order_id'] = (int) $order->id;
            $provisionData['last_upgrade_order_no'] = (string) $order->order_no;
            $provisionData['last_upgrade_invoice_id'] = $invoiceId;
            $provisionData['last_upgrade_target_label'] = (string) data_get($order->config_pricing_snapshot ?? [], 'meta.target_label', '');
            $provisionData['last_upgraded_at'] = now()->format('Y-m-d H:i:s');
            $provisionData['upgrade_error'] = null;

            DB::transaction(function () use ($service, $order, $provisionData) {
                $service->forceFill([
                    'provision_data' => $provisionData,
                ])->save();

                $order->forceFill([
                    'status' => OrderStatus::COMPLETED,
                ])->save();
            });
            $this->serviceBindingWriter()->syncServiceState($service, $service->product, $provisionData);
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $service->product,
                $provisionData,
                'success',
                null,
                $attemptRequestMeta,
                [
                    'upstream_host_id' => $resolvedHostId > 0 ? $resolvedHostId : null,
                    'upstream_invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                ],
                self::TRAFFIC_ORDER_KIND
            );

            $this->operationLogService->writeServiceConsoleLog($service, 'service.console.traffic_package.purchase', [
                'category' => 'upgrade',
                'summary' => '流量包已购买并同步到实例',
                'amount' => (string) number_format((float) ($order->amount ?? 0), 2, '.', ''),
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'invoice_id' => $invoiceId,
                'traffic_label' => (string) data_get($order->config_pricing_snapshot ?? [], 'meta.target_label', ''),
            ], [
                'actor_type' => 'system',
                'actor_user_id' => 0,
                'actor_name' => 'system',
            ]);

            return $service->fresh(['product.supplier', 'order']) ?? $service;
        } catch (\Throwable $exception) {
            $message = $exception instanceof BusinessException
                ? $exception->getMessage()
                : '流量包购买失败，请联系管理员处理';

            $provisionData = $this->serviceProvisionData($service);
            $provisionData['upgrade_error'] = $message;
            $provisionData['last_upgrade_attempt_at'] = now()->format('Y-m-d H:i:s');
            $provisionData['last_upgrade_kind'] = self::TRAFFIC_ORDER_KIND;

            DB::transaction(function () use ($service, $order, $provisionData) {
                $service->forceFill([
                    'provision_data' => $provisionData,
                ])->save();

                $order->forceFill([
                    'status' => OrderStatus::PROCESSING,
                ])->save();
            });
            $this->serviceBindingWriter()->syncServiceState($service, $service->product, $provisionData);
            $this->serviceBindingWriter()->recordProvisionAttempt(
                $service,
                $service->product,
                $provisionData,
                'failed',
                $message,
                $attemptRequestMeta,
                [
                    'upstream_host_id' => $resolvedHostId > 0 ? $resolvedHostId : null,
                ],
                self::TRAFFIC_ORDER_KIND
            );

            Log::error('[流量包购买] 上游执行失败', [
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'service_id' => (int) $service->id,
                'message' => $message,
                'exception' => $exception::class,
            ]);

            return $service->fresh(['product.supplier', 'order']) ?? $service;
        }
    }

    /**
     * 基于账单处理已支付流量包（无订单场景的过渡桥接）
     */
    public function processPaidTrafficPackageInvoice(Invoice $invoice): ?Service
    {
        $invoice->loadMissing(['order.service.product.supplier', 'order.product.supplier']);

        if ($invoice->order) {
            return $this->processPaidTrafficPackageOrder($invoice->order);
        }

        Log::warning('[流量包购买] 账单无关联订单，暂不支持直接基于账单执行流量包购买', [
            'invoice_id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no ?? '',
        ]);

        return null;
    }

    private function quoteForService(Service $service, array $selection): array
    {
        $context = $this->loadTrafficPackageContext($service);
        $selectionMeta = $this->resolveTrafficSelection($context['packages'], $context['host'], $selection);
        $payableAmount = round((float) ($selectionMeta['price'] ?? 0), 2);
        $mode = (string) ($context['mode'] ?? 'upgradeconfig');

        return [
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'upstream_host_id' => (int) $context['host_id'],
            'configoption' => $selectionMeta['configoption'],
            'selection' => $selectionMeta,
            'traffic' => $this->buildTrafficUsagePayload($context['host']),
            'mode' => $mode,
            'flow_packet_id' => (int) ($selectionMeta['flow_packet_id'] ?? 0),
            'pricing' => [
                'amount' => number_format($payableAmount, 2, '.', ''),
                'original_amount' => number_format($payableAmount, 2, '.', ''),
                'discount_amount' => '0.00',
                'billing_cycle' => 'one_time',
            ],
        ];
    }

    private function loadTrafficPackageContext(Service $service): array
    {
        $config = $this->settingService->getTrafficPackageConfig();
        throw_if(! ($config['enabled'] ?? true), new BusinessException('后台已关闭流量包购买功能'));

        [$supplier, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);
        $catalog = $this->resolveCatalogCapability($supplier);
        $jwt = '';
        $host = [];

        try {
            $remote = $this->detailService->fetchRemoteState($service, $supplier, $jwt);
            $host = is_array($remote['host'] ?? null) ? $remote['host'] : [];
            $jwt = trim((string) ($remote['jwt'] ?? $jwt));
        } catch (\Throwable $exception) {
            Log::info('[流量包] 上游 API 登录或详情不可用，尝试 flowpacket 网页会话', [
                'service_id' => (int) $service->id,
                'supplier_id' => (int) $supplier->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);
        }

        $productCategoryId = $this->effectiveProductGroupId($service);
        $configuredPackages = $this->settingService->getTrafficPackageCatalogForCategory(
            $productCategoryId,
            (string) ($service->product?->product_type ?? ''),
            (int) ($service->product_id ?? 0)
        );
        throw_if($configuredPackages === [], new BusinessException('当前商品分类未配置可售流量包'));

        // 优先尝试 upgradeconfig API
        $trafficOption = null;
        try {
            $upgradeConfig = $catalog->getHostUpgradeConfigOptions($supplier, $hostId, $jwt);
            $this->detailService->assertSuccess($upgradeConfig['response'], '读取流量包档位');
            $trafficOption = $this->findTrafficPackageOption($upgradeConfig['options'], $config);
        } catch (\Throwable $e) {
            Log::info('[流量包] upgradeconfig 不可用，尝试 flowpacket', [
                'service_id' => (int) $service->id,
                'host_id' => $hostId,
                'message' => $e->getMessage(),
            ]);
        }

        if (is_array($trafficOption)) {
            $availablePackages = $this->buildAvailableTrafficPackages($configuredPackages, $trafficOption, $host);
            throw_if($availablePackages === [], new BusinessException('当前商品分类配置的流量包与上游档位未匹配'));

            return [
                'supplier' => $supplier,
                'host_id' => $hostId,
                'jwt' => $jwt,
                'host' => $host,
                'mode' => 'upgradeconfig',
                'option' => $trafficOption,
                'packages' => $availablePackages,
            ];
        }

        // 回退到 flowpacket HTML 页面
        $flowPacketPackages = $this->tryPullFlowPacketFromHtml($supplier, $hostId, $jwt);
        throw_if($flowPacketPackages === [], new BusinessException('当前服务暂不支持流量包购买'));

        $availablePackages = $this->buildAvailableFlowPacketPackages($configuredPackages, $flowPacketPackages, $host);
        throw_if($availablePackages === [], new BusinessException('当前商品分类配置的流量包与上游流量包未匹配'));

        return [
            'supplier' => $supplier,
            'host_id' => $hostId,
            'jwt' => $jwt,
            'host' => $host,
            'mode' => 'flowpacket',
            'option' => null,
            'packages' => $availablePackages,
        ];
    }

    private function findManagedService(User $user, int $serviceId): Service
    {
        return $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);
    }

    private function findTrafficPackageOption(array $options, array $config): ?array
    {
        $optionField = trim((string) ($config['option_field'] ?? 'flow_limit'));
        $optionKeyword = trim((string) ($config['option_keyword'] ?? '流量'));
        $allowChoiceMode = (bool) ($config['allow_choice_mode'] ?? true);
        $allowQuantityMode = (bool) ($config['allow_quantity_mode'] ?? true);
        $fallbackMatches = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $field = trim((string) ($option['field'] ?? ''));
            $name = trim((string) ($option['name'] ?? $option['option_name'] ?? ''));
            $isRange = $this->isRangeTrafficOption($option);

            if (($isRange && ! $allowQuantityMode) || (! $isRange && ! $allowChoiceMode)) {
                continue;
            }

            if ($optionField !== '' && $field === $optionField) {
                return $option;
            }

            if ($optionKeyword !== '' && str_contains($name, $optionKeyword)) {
                $fallbackMatches[] = $option;
            }
        }

        foreach ($fallbackMatches as $option) {
            $field = trim((string) ($option['field'] ?? ''));

            if (str_contains($field, 'traffic_bill') || str_contains($field, 'bill_type')) {
                continue;
            }

            return $option;
        }

        return null;
    }

    private function resolveAdminImportSource(int $secondProductGroupId, ?int $thirdProductGroupId, string $productType = ''): array
    {
        $services = Service::query()
            ->with(['product.supplier'])
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::PENDING, ServiceStatus::SUSPENDED])
            ->whereHas('product', function ($query) use ($secondProductGroupId, $thirdProductGroupId, $productType) {
                $this->applyProductGroupScope($query, $secondProductGroupId, $thirdProductGroupId);

                if (trim($productType) !== '') {
                    $query->where('product_type', trim($productType));
                }
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        foreach ($services as $service) {
            try {
                [$supplier, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);
                $jwt = '';

                try {
                    $catalog = $this->resolveCatalogCapability($supplier);
                    $jwt = $catalog->login($supplier);
                } catch (\Throwable $exception) {
                    Log::info('[流量包拉取] 上游 API 登录失败，尝试使用网页登录会话抓取 flowpacket', [
                        'supplier_id' => (int) $supplier->id,
                        'service_id' => (int) $service->id,
                        'host_id' => $hostId,
                        'message' => $exception->getMessage(),
                    ]);
                }

                return [$service, $supplier, $hostId, $jwt];
            } catch (\Throwable) {
                continue;
            }
        }

        throw new BusinessException('该分类下暂无已接入上游控制台的服务实例，正在尝试回退到商品上游模板');
    }

    private function pullCatalogTemplateFromProduct(
        int $secondProductGroupId,
        ?int $thirdProductGroupId,
        string $productType = '',
        string $fallbackReason = ''
    ): array {
        $product = Product::query()
            ->with('supplier')
            ->tap(fn ($query) => $this->applyProductGroupScope($query, $secondProductGroupId, $thirdProductGroupId))
            ->when(trim($productType) !== '', fn ($query) => $query->where('product_type', trim($productType)))
            ->where('status', 1)
            ->tap(fn (Builder $query) => $this->applyHasUpstreamProductBindingScope($query))
            ->orderByDesc('id')
            ->first();

        throw_if(! $product instanceof Product, new BusinessException('该分类下暂无已绑定上游商品的商品记录，无法从上游接口拉取流量包配置'));

        $supplier = $this->resolveProductSupplier($product);
        throw_if(! $supplier instanceof Supplier, new BusinessException('商品未绑定有效的供应商配置'));
        $upstreamProductId = $this->resolveProductUpstreamProductId($product);
        throw_if($upstreamProductId <= 0, new BusinessException('商品尚未绑定有效的上游商品 ID'));

        $template = $this->resolveCatalogCapability($supplier)->getProductConfigTemplate($supplier, $upstreamProductId);
        $config = $this->settingService->getTrafficPackageConfig();
        $trafficOption = $this->findTrafficPackageOption((array) ($template['config_options'] ?? []), $config);

        throw_if(! is_array($trafficOption), new BusinessException('上游商品模板中未找到流量包配置项'));
        throw_if($this->isRangeTrafficOption($trafficOption), new BusinessException('当前上游商品流量包为数量区间模式，暂不支持一键拉取为固定档位，请手动维护价格'));

        $packages = $this->pullPackagesFromProductOption($trafficOption);
        throw_if($packages === [], new BusinessException('未从上游商品模板解析到可导入的流量包档位'));

        return [
            'source' => [
                'mode' => 'product',
                'service_id' => 0,
                'service_name' => '',
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => (string) ($supplier->name ?? ''),
                'upstream_host_id' => 0,
                'fallback_reason' => $fallbackReason,
            ],
            'traffic' => [
                'usage' => '0.00',
                'limit' => 0,
                'remaining' => '',
                'usage_label' => '0G',
                'limit_label' => '模板档位',
                'remaining_label' => '模板档位',
                'usage_percent' => null,
                'limited' => false,
            ],
            'packages' => $packages,
        ];
    }

    private function pullCatalogTemplateFromSupplierProduct(int $supplierId, int $upstreamProductId): array
    {
        $supplier = Supplier::query()->find($supplierId);
        throw_if(! $supplier instanceof Supplier, new BusinessException('供应商不存在'));
        $supplier = $this->bindingResolver()->supplierWithRuntimeCredentials($supplier);

        $template = $this->resolveCatalogCapability($supplier)->getProductConfigTemplate($supplier, $upstreamProductId);
        $config = $this->settingService->getTrafficPackageConfig();
        $trafficOption = $this->findTrafficPackageOption((array) ($template['config_options'] ?? []), $config);

        throw_if(! is_array($trafficOption), new BusinessException('上游商品模板中未找到流量包配置项'));
        throw_if($this->isRangeTrafficOption($trafficOption), new BusinessException('当前上游商品流量包为数量区间模式，暂不支持一键拉取为固定档位，请手动维护价格'));

        $packages = $this->pullPackagesFromProductOption($trafficOption);
        throw_if($packages === [], new BusinessException('未从上游商品模板解析到可导入的流量包档位'));

        return [
            'source' => [
                'mode' => 'supplier_product',
                'service_id' => 0,
                'service_name' => '',
                'product_id' => 0,
                'product_name' => (string) (($template['product']['name'] ?? '') ?: ''),
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => (string) ($supplier->name ?? ''),
                'upstream_host_id' => 0,
                'upstream_product_id' => $upstreamProductId,
            ],
            'traffic' => [
                'usage' => '0.00',
                'limit' => 0,
                'remaining' => '',
                'usage_label' => '0G',
                'limit_label' => '模板档位',
                'remaining_label' => '模板档位',
                'usage_percent' => null,
                'limited' => false,
            ],
            'packages' => $packages,
        ];
    }

    /**
     * 按本地商品自身绑定的上游链路拉取流量包档位：
     *   优先尝试该商品下一个可管理的服务实例（能返回真实的上游报价），
     *   服务路径失败时回退到该商品对应的上游商品模板。
     */
    private function pullCatalogTemplateFromLocalProduct(int $productId): array
    {
        $product = Product::query()
            ->with('supplier')
            ->find($productId);

        throw_if(! $product instanceof Product, new BusinessException('来源商品不存在'));

        $supplier = $this->resolveProductSupplier($product);
        throw_if(! $supplier instanceof Supplier, new BusinessException('来源商品未绑定有效的供应商配置'));

        $upstreamProductId = $this->resolveProductUpstreamProductId($product);
        throw_if($upstreamProductId <= 0, new BusinessException('来源商品尚未绑定上游商品 ID，无法拉取流量包'));

        $serviceResult = $this->tryPullFromLocalProductService($product, $supplier);
        if ($serviceResult !== null) {
            return $serviceResult;
        }

        $template = $this->resolveCatalogCapability($supplier)->getProductConfigTemplate($supplier, $upstreamProductId);
        $config = $this->settingService->getTrafficPackageConfig();
        $trafficOption = $this->findTrafficPackageOption((array) ($template['config_options'] ?? []), $config);

        throw_if(! is_array($trafficOption), new BusinessException('来源商品的上游模板中未找到流量包配置项'));
        throw_if($this->isRangeTrafficOption($trafficOption), new BusinessException('来源商品的上游流量包为数量区间模式，暂不支持一键拉取为固定档位，请手动维护价格'));

        $packages = $this->pullPackagesFromProductOption($trafficOption);
        throw_if($packages === [], new BusinessException('未从来源商品的上游模板解析到可导入的流量包档位'));

        return [
            'source' => [
                'mode' => 'local_product_template',
                'service_id' => 0,
                'service_name' => '',
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => (string) ($supplier->name ?? ''),
                'upstream_host_id' => 0,
                'upstream_product_id' => $upstreamProductId,
            ],
            'traffic' => [
                'usage' => '0.00',
                'limit' => 0,
                'remaining' => '',
                'usage_label' => '0G',
                'limit_label' => '模板档位',
                'remaining_label' => '模板档位',
                'usage_percent' => null,
                'limited' => false,
            ],
            'packages' => $packages,
        ];
    }

    /**
     * 尝试从本地商品已开通的服务实例拉取流量包（可得到上游实时报价）。
     * 失败时返回 null，由上层回退到商品模板。
     */
    private function tryPullFromLocalProductService(Product $product, Supplier $supplier): ?array
    {
        $services = Service::query()
            ->with(['product.supplier'])
            ->whereHas('product', function ($query) use ($product, $supplier) {
                $this->applyProductGroupScope(
                    $query,
                    (int) ($product->second_product_group_id ?? 0),
                    ((int) ($product->third_product_group_id ?? 0)) ?: null
                );

                $query
                    ->where('product_type', (string) $product->product_type)
                    ->tap(fn (Builder $query) => $this->applyBoundSupplierScope($query, $supplier));
            })
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::PENDING, ServiceStatus::SUSPENDED])
            ->orderByRaw('CASE WHEN product_id = ? THEN 0 ELSE 1 END', [(int) $product->id])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($services as $service) {
            try {
                [$serviceSupplier, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);
                $catalog = $this->resolveCatalogCapability($serviceSupplier);
                $jwt = '';

                try {
                    $jwt = $catalog->login($serviceSupplier);
                } catch (\Throwable $exception) {
                    Log::info('[流量包拉取] 来源商品服务 API 登录失败，尝试使用网页登录会话抓取 flowpacket', [
                        'supplier_id' => (int) $serviceSupplier->id,
                        'product_id' => (int) $product->id,
                        'service_id' => (int) $service->id,
                        'host_id' => $hostId,
                        'message' => $exception->getMessage(),
                    ]);
                }

                $host = [];
                $flowPacketPackages = $this->tryPullFlowPacketFromHtml($serviceSupplier, $hostId, $jwt);
                if ($flowPacketPackages !== []) {
                    try {
                        $detailResponse = $catalog->getHostDetail($serviceSupplier, $hostId, $jwt);
                        $this->detailService->assertSuccess($detailResponse, '读取实例流量信息');
                        $detailPayload = $this->detailService->extractPayload($detailResponse);
                        $host = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                    } catch (\Throwable) {
                        $host = [];
                    }

                    return [
                        'source' => [
                            'mode' => 'flowpacket',
                            'service_id' => (int) $service->id,
                            'service_name' => (string) $service->name,
                            'product_id' => (int) $product->id,
                            'product_name' => (string) $product->name,
                            'supplier_id' => (int) $serviceSupplier->id,
                            'supplier_name' => (string) ($serviceSupplier->name ?? ''),
                            'upstream_host_id' => $hostId,
                            'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
                        ],
                        'traffic' => $this->buildTrafficUsagePayload($host),
                        'packages' => $flowPacketPackages,
                    ];
                }

                $detailResponse = $catalog->getHostDetail($serviceSupplier, $hostId, $jwt);
                $this->detailService->assertSuccess($detailResponse, '读取实例流量信息');
                $detailPayload = $this->detailService->extractPayload($detailResponse);
                $host = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];

                // 回退到 upgradeconfig API
                $config = $this->settingService->getTrafficPackageConfig();
                $upgradeConfig = $catalog->getHostUpgradeConfigOptions($serviceSupplier, $hostId, $jwt);
                $this->detailService->assertSuccess($upgradeConfig['response'], '读取流量包档位');
                $trafficOption = $this->findTrafficPackageOption($upgradeConfig['options'], $config);

                if (! is_array($trafficOption) || $this->isRangeTrafficOption($trafficOption)) {
                    continue;
                }

                $packages = $this->pullPackagesFromUpstreamOption($serviceSupplier, $hostId, $jwt, $trafficOption, $host);
                if ($packages === []) {
                    continue;
                }

                return [
                    'source' => [
                        'mode' => 'local_product_service',
                        'service_id' => (int) $service->id,
                        'service_name' => (string) $service->name,
                        'product_id' => (int) $product->id,
                        'product_name' => (string) $product->name,
                        'supplier_id' => (int) $serviceSupplier->id,
                        'supplier_name' => (string) ($serviceSupplier->name ?? ''),
                        'upstream_host_id' => $hostId,
                        'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
                    ],
                    'traffic' => $this->buildTrafficUsagePayload($host),
                    'packages' => $packages,
                ];
            } catch (\Throwable $exception) {
                Log::info('[流量包拉取] 来源商品服务实例尝试失败，继续下一条', [
                    'product_id' => (int) $product->id,
                    'service_id' => (int) $service->id,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }
        }

        return null;
    }

    private function applyProductGroupScope($query, int $secondProductGroupId, ?int $thirdProductGroupId): void
    {
        $query->inCurrentProductGroup($thirdProductGroupId !== null && $thirdProductGroupId > 0 ? $thirdProductGroupId : $secondProductGroupId);
    }

    private function pullPackagesFromUpstreamOption(Supplier $supplier, int $hostId, string $jwt, array $option, array $host): array
    {
        $catalog = $this->resolveCatalogCapability($supplier);
        $currentLimit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);
        $currentQty = $this->normalizeTrafficNumeric($option['current_qty'] ?? null);
        $currentRaw = $currentLimit > 0 ? $currentLimit : $currentQty;
        $optionId = (int) ($option['id'] ?? 0);

        return collect($option['sub'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item, int $index) use ($supplier, $hostId, $jwt, $optionId, $currentRaw) {
                $subId = (int) ($item['id'] ?? 0);
                $catalog = $this->resolveCatalogCapability($supplier);
                $targetValue = $this->extractTrafficRawValue(
                    (string) ($item['option_name_first'] ?? $item['option_name'] ?? $item['version'] ?? '')
                );

                if ($subId <= 0 || $targetValue <= 0 || ($currentRaw > 0 && $targetValue <= $currentRaw)) {
                    return null;
                }

                $label = $this->resolveTrafficSubOptionLabel($item, $targetValue);
                $price = '0.00';

                try {
                    $previewResponse = $catalog->previewHostConfigUpgrade(
                        $supplier,
                        $hostId,
                        [(string) $optionId => $subId],
                        $jwt
                    );
                    $this->detailService->assertSuccess($previewResponse, '预览流量包报价');
                    $previewPayload = $this->detailService->extractPayload($previewResponse);
                    $price = number_format($this->resolvePreviewPayableAmount($previewPayload), 2, '.', '');
                } catch (\Throwable $exception) {
                    Log::warning('[流量包拉取] 预览上游价格失败，已回退为 0.00', [
                        'supplier_id' => (int) $supplier->id,
                        'host_id' => $hostId,
                        'option_id' => $optionId,
                        'sub_id' => $subId,
                        'target_value' => $targetValue,
                        'message' => $exception->getMessage(),
                    ]);
                }

                return [
                    'label' => $label,
                    'target_value' => $targetValue,
                    'price' => $price,
                    'enabled' => 1,
                    'sort_order' => $index + 1,
                    'option_id' => $optionId,
                    'sub_id' => $subId,
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    private function resolveCatalogCapability(Supplier $supplier): object
    {
        $supplier = $this->bindingResolver()->supplierWithRuntimeCredentials($supplier);

        return $this->providerResolver
            ->resolveForSupplier($supplier)
            ->require(ProvidesConsoleCatalog::class, '当前供应商不支持流量包能力');
    }

    private function pullPackagesFromProductOption(array $option): array
    {
        return collect($option['sub'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item, int $index) use ($option) {
                $targetValue = $this->extractTrafficRawValue(
                    (string) ($item['option_name_first'] ?? $item['option_name'] ?? $item['version'] ?? '')
                );
                if ($targetValue <= 0) {
                    return null;
                }

                return [
                    'label' => $this->resolveTrafficSubOptionLabel($item, $targetValue),
                    'target_value' => $targetValue,
                    'price' => $this->resolveProductOptionPrice($item),
                    'enabled' => 1,
                    'sort_order' => $index + 1,
                    'option_id' => (int) ($option['id'] ?? 0),
                    'sub_id' => (int) ($item['id'] ?? 0),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    private function buildAvailableTrafficPackages(array $configuredPackages, array $option, array $host): array
    {
        $currentLimit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);
        $currentQty = $this->normalizeTrafficNumeric($option['current_qty'] ?? null);
        $currentRaw = $currentLimit > 0 ? $currentLimit : $currentQty;
        $optionId = (int) ($option['id'] ?? 0);

        return collect($configuredPackages)
            ->filter(fn ($item) => is_array($item) && (int) ($item['enabled'] ?? 1) === 1)
            ->map(function (array $item) use ($option, $currentRaw, $optionId) {
                $targetValue = max((int) ($item['target_value'] ?? 0), 0);
                if ($targetValue <= 0 || ($currentRaw > 0 && $targetValue <= $currentRaw)) {
                    return null;
                }

                $configoption = $this->resolveTrafficConfigOptionPayload($option, $targetValue);
                if ($configoption === []) {
                    return null;
                }

                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') {
                    $label = $this->formatTrafficAmount($targetValue);
                }

                return [
                    'option_id' => $optionId,
                    'target_value' => $targetValue,
                    'target_label' => $label,
                    'label' => $label,
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'sort_order' => max((int) ($item['sort_order'] ?? 0), 0),
                    'configoption' => $configoption,
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->sortBy([
                ['sort_order', 'asc'],
                ['target_value', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * 从 flowpacket HTML 解析出的档位与后台已配置的流量包做匹配，
     * 返回可售列表。匹配条件为 target_value 相等。
     */
    private function buildAvailableFlowPacketPackages(array $configuredPackages, array $flowPacketPackages, array $host): array
    {
        $currentLimit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);

        $flowPacketMap = [];
        foreach ($flowPacketPackages as $fp) {
            if (is_array($fp) && (int) ($fp['target_value'] ?? 0) > 0) {
                $flowPacketMap[(int) $fp['target_value']] = $fp;
            }
        }

        return collect($configuredPackages)
            ->filter(fn ($item) => is_array($item) && (int) ($item['enabled'] ?? 1) === 1)
            ->map(function (array $item) use ($flowPacketMap, $currentLimit) {
                $targetValue = max((int) ($item['target_value'] ?? 0), 0);
                if ($targetValue <= 0 || ($currentLimit > 0 && $targetValue <= $currentLimit)) {
                    return null;
                }

                $matched = $flowPacketMap[$targetValue] ?? null;
                if (! is_array($matched)) {
                    return null;
                }

                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') {
                    $label = $this->formatTrafficAmount($targetValue);
                }

                return [
                    'option_id' => 0,
                    'target_value' => $targetValue,
                    'target_label' => $label,
                    'label' => $label,
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'sort_order' => max((int) ($item['sort_order'] ?? 0), 0),
                    'configoption' => [],
                    'flow_packet_id' => (int) ($matched['flow_packet_id'] ?? 0),
                    'mode' => 'flowpacket',
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->sortBy([
                ['sort_order', 'asc'],
                ['target_value', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function resolveTrafficConfigOptionPayload(array $option, int $targetValue): array
    {
        $optionId = (int) ($option['id'] ?? 0);
        if ($optionId <= 0 || $targetValue <= 0) {
            return [];
        }

        if ($this->isRangeTrafficOption($option)) {
            $minQty = max((int) ($option['qty_minimum'] ?? 0), 1);
            $maxQty = max((int) ($option['qty_maximum'] ?? 0), $minQty);

            if ($targetValue < $minQty || $targetValue > $maxQty) {
                return [];
            }

            return [(string) $optionId => $targetValue];
        }

        $matchedSub = collect($option['sub'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->first(function (array $item) use ($targetValue) {
                $rawValue = $this->extractTrafficRawValue(
                    (string) ($item['option_name_first'] ?? $item['option_name'] ?? $item['version'] ?? '')
                );

                return $rawValue === $targetValue;
            });

        if (! is_array($matchedSub) || (int) ($matchedSub['id'] ?? 0) <= 0) {
            return [];
        }

        return [(string) $optionId => (int) $matchedSub['id']];
    }

    private function resolveProductOptionPrice(array $item): string
    {
        $pricing = is_array($item['pricing'] ?? null) ? $item['pricing'] : [];

        foreach (['one_time', 'onetime', 'monthly', 'quarterly', 'semiannually', 'annually'] as $key) {
            $value = $pricing[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return number_format(max((float) $value, 0), 2, '.', '');
            }
        }

        return '0.00';
    }

    private function buildTrafficOptionPayload(array $option, array $host): array
    {
        $mode = $this->isRangeTrafficOption($option) ? 'quantity' : 'choice';
        $currentLimit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);
        $currentQty = $this->normalizeTrafficNumeric($option['current_qty'] ?? null);
        $effectiveCurrent = $currentLimit > 0 ? $currentLimit : $currentQty;
        $currentLabel = trim((string) ($option['current_label'] ?? ''));
        if ($currentLabel === '' && $effectiveCurrent > 0) {
            $currentLabel = $this->formatTrafficAmount($effectiveCurrent);
        }

        $choices = collect($option['sub'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($effectiveCurrent, $option) {
                $rawValue = $this->extractTrafficRawValue(
                    (string) ($item['option_name_first'] ?? $item['option_name'] ?? $item['version'] ?? '')
                );
                $label = $this->resolveTrafficSubOptionLabel($item, $rawValue);
                $delta = $rawValue > 0 && $effectiveCurrent > 0 ? max($rawValue - $effectiveCurrent, 0) : 0;

                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'label' => $label,
                    'value' => trim((string) (($item['option_name_first'] ?? null) ?: ($item['id'] ?? ''))),
                    'raw_value' => $rawValue > 0 ? $rawValue : null,
                    'is_current' => (int) ($item['id'] ?? 0) === (int) (($option['current_sub_id'] ?? null) ?: 0),
                    'delta_label' => $delta > 0 ? '+'.$this->formatTrafficAmount($delta) : '',
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (int) ($option['id'] ?? 0),
            'field' => (string) ($option['field'] ?? 'flow_limit'),
            'name' => (string) ($option['name'] ?? '流量'),
            'mode' => $mode,
            'unit' => trim((string) ($option['unit'] ?? 'G')) ?: 'G',
            'current_qty' => $currentQty > 0 ? $currentQty : null,
            'current_limit' => $currentLimit > 0 ? $currentLimit : null,
            'current_label' => $currentLabel !== '' ? $currentLabel : '--',
            'min_qty' => (int) ($option['qty_minimum'] ?? 0),
            'max_qty' => (int) ($option['qty_maximum'] ?? 0),
            'choices' => $choices,
        ];
    }

    private function buildTrafficUsagePayload(array $host): array
    {
        $usage = $this->normalizeTrafficFloat($host['bwusage'] ?? null);
        $limit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);
        $remaining = $limit > 0 ? max($limit - $usage, 0) : null;
        $usagePercent = $limit > 0
            ? min(round(($usage / $limit) * 100, 2), 100.0)
            : null;

        return [
            'usage' => number_format($usage, 2, '.', ''),
            'limit' => $limit > 0 ? $limit : 0,
            'remaining' => $remaining !== null ? number_format($remaining, 2, '.', '') : '',
            'usage_label' => $this->formatTrafficAmount($usage),
            'limit_label' => $limit > 0 ? $this->formatTrafficAmount($limit) : '不限',
            'remaining_label' => $remaining !== null ? $this->formatTrafficAmount($remaining) : '不限',
            'usage_percent' => $usagePercent,
            'limited' => $limit > 0,
        ];
    }

    private function resolveTrafficSelection(array $packages, array $host, array $selection): array
    {
        $currentLimit = $this->normalizeTrafficNumeric($host['bwlimit'] ?? null);
        $currentRaw = $currentLimit;
        $currentLabel = '';
        if ($currentLabel === '' && $currentRaw > 0) {
            $currentLabel = $this->formatTrafficAmount($currentRaw);
        }

        $targetValue = (int) ($selection['target_value'] ?? 0);
        $matchedPackage = collect($packages)
            ->filter(fn ($item) => is_array($item))
            ->first(fn (array $item) => (int) ($item['target_value'] ?? 0) === $targetValue);

        throw_if(! is_array($matchedPackage), new BusinessException('请选择有效的流量包档位'));

        return [
            'option_id' => (int) ($matchedPackage['option_id'] ?? 0),
            'target_value' => (int) ($matchedPackage['target_value'] ?? 0),
            'price' => (string) ($matchedPackage['price'] ?? '0.00'),
            'configoption' => (array) ($matchedPackage['configoption'] ?? []),
            'flow_packet_id' => (int) ($matchedPackage['flow_packet_id'] ?? 0),
            'current_label' => $currentLabel !== '' ? $currentLabel : '--',
            'target_label' => (string) ($matchedPackage['target_label'] ?? $matchedPackage['label'] ?? ''),
            'target_snapshot' => (int) ($matchedPackage['target_value'] ?? 0),
        ];
    }

    private function resolvePreviewPayableAmount(array $payload): float
    {
        $candidates = [
            $payload['total'] ?? null,
            $payload['saleproducts'] ?? null,
            $payload['amount_total'] ?? null,
            $payload['subtotal'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (! is_numeric($candidate)) {
                continue;
            }

            return round(max((float) $candidate, 0), 2);
        }

        throw new BusinessException('上游未返回有效的流量包报价');
    }

    private function resolvePreviewOriginalAmount(array $payload, float $fallback): float
    {
        foreach ([$payload['subtotal'] ?? null, $payload['amount_total'] ?? null, $payload['total'] ?? null] as $candidate) {
            if ($candidate === null || $candidate === '' || ! is_numeric($candidate)) {
                continue;
            }

            return round(max((float) $candidate, 0), 2);
        }

        return $fallback;
    }

    private function isTrafficPackageOrderAlreadyCompleted(Order $order, Service $service): bool
    {
        if ((int) $order->status === OrderStatus::COMPLETED) {
            return true;
        }

        $provisionData = $this->serviceProvisionData($service);

        return (int) ($provisionData['last_upgrade_order_id'] ?? 0) === (int) $order->id
            && (string) ($provisionData['last_upgrade_kind'] ?? '') === self::TRAFFIC_ORDER_KIND;
    }

    private function applyHasUpstreamProductBindingScope(Builder $query): void
    {
        if ($this->hasProductBindingTables()) {
            $query->whereExists(function ($subQuery): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('product_upstream_bindings as pub')
                    ->whereColumn('pub.product_id', 'products.id')
                    ->where('pub.status', 1);
            });

            return;
        }

        $query->whereRaw('0 = 1');
    }

    private function applyBoundSupplierScope(Builder $query, Supplier $supplier): void
    {
        if ($this->hasProductBindingTables()) {
            $query->whereExists(function ($subQuery) use ($supplier): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('product_upstream_bindings as pub')
                    ->join('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id')
                    ->whereColumn('pub.product_id', 'products.id')
                    ->where('spb.supplier_id', (int) $supplier->id);
            });

            return;
        }

        $query->whereRaw('0 = 1');
    }

    private function resolveProductSupplier(Product $product): ?Supplier
    {
        $boundSupplier = $this->bindingResolver()->supplierForProduct($product);
        if ($boundSupplier instanceof Supplier) {
            return $boundSupplier;
        }

        return null;
    }

    private function resolveProductUpstreamProductId(Product $product): int
    {
        $bindingUpstreamProductId = $this->bindingResolver()->upstreamProductIdForProduct($product);
        if ($bindingUpstreamProductId !== null) {
            return (int) $bindingUpstreamProductId;
        }

        return 0;
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ?? app(PluginBindingResolver::class);
    }

    private function serviceBindingWriter(): ServiceUpstreamBindingWriter
    {
        return $this->serviceBindingWriter ??= app(ServiceUpstreamBindingWriter::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(Service $service): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = $this->bindingResolver()->serviceProvisionProjection($service);
        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return $this->bindingResolver()->sanitizeServiceProvisionData($provisionData);
    }

    /**
     * @param  array<string, mixed>  $configOption
     * @return array<string, mixed>
     */
    private function trafficPackageAttemptRequestMeta(Order $order, string $mode, array $configOption, int $flowPacketId): array
    {
        $traceId = trim((string) ($order->trace_id ?? ''));
        if ($traceId === '' && (int) $order->id > 0 && Schema::hasColumn('orders', 'trace_id')) {
            $traceId = trim((string) DB::table('orders')->where('id', (int) $order->id)->value('trace_id'));
        }

        return array_filter([
            'kind' => self::TRAFFIC_ORDER_KIND,
            'order_id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'trace_id' => $traceId,
            'mode' => $mode,
            'flow_packet_id' => $flowPacketId > 0 ? $flowPacketId : null,
            'configoption_keys' => array_keys($configOption),
            'target_label' => (string) data_get($order->config_pricing_snapshot ?? [], 'meta.target_label', ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function hasProductBindingTables(): bool
    {
        try {
            return Schema::hasTable('product_upstream_bindings') && Schema::hasTable('supplier_plugin_bindings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 尝试从上游 /servicedetail?action=flowpacket HTML 页面抓取流量包档位。
     * 抓取失败或解析为空时返回空数组，不抛异常，以便上层回退到其他数据源。
     */
    private function effectiveProductGroupId(Service $service): int
    {
        return (int) ($service->product?->product_group_id ?? 0);
    }

    private function tryPullFlowPacketFromHtml(Supplier $supplier, int $hostId, string $jwt): array
    {
        try {
            return $this->detailService->fetchFlowPacketList($supplier, $hostId, $jwt);
        } catch (\Throwable $exception) {
            Log::info('[流量包拉取] flowpacket HTML 抓取失败，将回退到 API', [
                'supplier_id' => (int) $supplier->id,
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function isRangeTrafficOption(array $option): bool
    {
        return in_array((int) ($option['option_type'] ?? 0), self::RANGE_OPTION_TYPES, true);
    }

    private function resolveTrafficSubOptionLabel(array $item, int $rawValue = 0): string
    {
        $label = trim((string) ($item['version'] ?? $item['option_name'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        if ($rawValue > 0) {
            return $this->formatTrafficAmount($rawValue);
        }

        return trim((string) ($item['option_name_first'] ?? $item['id'] ?? '--'));
    }

    private function extractTrafficRawValue(string $value): int
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 0;
        }

        if (is_numeric($normalized)) {
            return max((int) round((float) $normalized), 0);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*T/i', $normalized, $matches) === 1) {
            return (int) round(((float) $matches[1]) * 1024);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*G/i', $normalized, $matches) === 1) {
            return (int) round((float) $matches[1]);
        }

        return 0;
    }

    private function normalizeTrafficNumeric(mixed $value): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return 0;
        }

        return max((int) round((float) $value), 0);
    }

    private function normalizeTrafficFloat(mixed $value): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return 0.0;
        }

        return round(max((float) $value, 0), 2);
    }

    private function formatTrafficAmount(float|int $value): string
    {
        $normalized = round((float) $value, 2);

        if ($normalized <= 0) {
            return '0G';
        }

        if ($normalized >= 1024) {
            return $this->trimFloat($normalized / 1024).'TB';
        }

        return $this->trimFloat($normalized).'G';
    }

    private function trimFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
