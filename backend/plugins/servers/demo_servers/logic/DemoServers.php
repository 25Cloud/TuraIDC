<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\DemoServers\Logic;

use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;

class DemoServers implements ProvidesConsoleAccess, ProvidesConsoleCatalog, ProvidesConsoleNetwork, ProvidesConsoleRuntime, ProvidesConsoleSecurity, ProvidesProvisioning, ProvidesRenewal, ProvidesScheduledAuthRefresh, ProvidesStatusSync, ProvidesSupplierFormSchema, UpstreamDriver
{
    private const CAPABILITIES = [
        ProvidesConsoleAccess::class,
        ProvidesConsoleCatalog::class,
        ProvidesConsoleNetwork::class,
        ProvidesConsoleRuntime::class,
        ProvidesConsoleSecurity::class,
        ProvidesProvisioning::class,
        ProvidesRenewal::class,
        ProvidesScheduledAuthRefresh::class,
        ProvidesStatusSync::class,
    ];

    public function key(): string
    {
        return 'demo_servers';
    }

    public function label(): string
    {
        return 'Demo 上游服务';
    }

    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this : null;
    }

    public function supplierFormSchema(): array
    {
        return [
            'help' => 'Demo 上游不请求真实接口，只需要可选的模拟区域配置。',
            'fields' => [
                [
                    'key' => 'demo_region',
                    'label' => '模拟区域',
                    'type' => 'text',
                    'required' => false,
                    'placeholder' => 'ap-demo-1',
                    'default' => 'ap-demo-1',
                ],
            ],
        ];
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'server.metadata' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'key' => $this->key(),
                    'label' => $this->label(),
                    'capabilities' => $this->capabilities(),
                    'demo_region' => $this->resolveRegion($config),
                ],
            ],
            'server.supports' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'supported' => $this->supports((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.resolve_capability' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'resolved' => $this->resolve((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.supplier_form_schema' => [
                'success' => true,
                'action' => $action,
                'data' => $this->supplierFormSchema(),
            ],
            'server.health_check' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'healthy' => true,
                    'provider_key' => $this->key(),
                    'message' => 'Demo 上游服务加载正常',
                    'demo_region' => $this->resolveRegion($config),
                ],
            ],
            default => [
                'success' => false,
                'action' => $action,
                'message' => '不支持的上游插件动作',
                'data' => [],
            ],
        };
    }

    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'message' => 'Demo 上游服务加载正常',
        ];
    }

    public function login(Supplier $supplier): string
    {
        return 'demo-jwt-'.$this->supplierId($supplier);
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return 'demo-jwt-refreshed-'.$this->supplierId($supplier);
    }

    public function loginResponse(Supplier $supplier): array
    {
        return [
            'status' => 200,
            'data' => [
                'jwt' => $this->login($supplier),
                'supplier_id' => $this->supplierId($supplier),
            ],
        ];
    }

    public function getUserProfile(Supplier $supplier): array
    {
        return [
            'status' => 200,
            'data' => [
                'id' => $this->supplierId($supplier),
                'name' => (string) ($supplier->name ?? 'Demo 上游供应商'),
            ],
        ];
    }

    public function getBalance(Supplier $supplier): array
    {
        return [
            'status' => 200,
            'data' => [
                'balance' => '9999.00',
                'currency' => 'CNY',
            ],
        ];
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        $products = [
            $this->catalogProduct(1001, 'Demo 云服务器 1C2G', 'cloud', '39.00', 100),
            $this->catalogProduct(1002, 'Demo 独立服务器 E3', 'server', '299.00', 10),
        ];

        return [
            'groups' => [
                [
                    'key' => 'demo-cloud',
                    'label' => 'Demo 产品 / 云服务器',
                    'items' => [$products[0]],
                ],
                [
                    'key' => 'demo-server',
                    'label' => 'Demo 产品 / 独立服务器',
                    'items' => [$products[1]],
                ],
            ],
            'products' => $products,
        ];
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        return $this->getProductConfigTemplate($supplier, $productId);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $items = [];
        foreach ($productIds as $productId) {
            $items[(int) $productId] = $this->getProductConfigTemplate($supplier, (int) $productId);
        }

        return $items;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $items = [];
        foreach ($productIds as $productId) {
            $items[(int) $productId] = [
                'stock' => 100,
                'qty' => 100,
                'stock_control' => 1,
            ];
        }

        return $items;
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        return [
            'product_id' => $productId,
            'config_options' => [
                [
                    'id' => 1,
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'type' => 'select',
                    'sub' => [
                        ['id' => 11, 'name' => '1 核', 'value' => 1],
                        ['id' => 12, 'name' => '2 核', 'value' => 2],
                    ],
                ],
                [
                    'id' => 2,
                    'field' => 'memory',
                    'name' => '内存',
                    'type' => 'select',
                    'sub' => [
                        ['id' => 21, 'name' => '2G', 'value' => 2],
                        ['id' => 22, 'name' => '4G', 'value' => 4],
                    ],
                ],
            ],
        ];
    }

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $hostId = 900000 + max(1, (int) ($order->id ?? 0));

        return [
            'requested_host' => 'demo-'.$hostId,
            'upstream_invoice_id' => 800000 + max(1, (int) ($order->id ?? 0)),
            'upstream_host_ids' => [$hostId],
            'upstream_host_id' => $hostId,
            'host_detail' => $this->hostPayload($hostId),
        ];
    }

    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return [
            'status' => 200,
            'data' => [
                'product_id' => $productId,
                'hostname_rule' => [
                    'prefix' => 'demo',
                    'min_length' => 6,
                    'max_length' => 32,
                ],
            ],
        ];
    }

    public function getHostRenewInfo(Supplier $supplier, int $hostId, ?string $billingCycle = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'billingcycle' => $billingCycle ?: 'monthly',
                'amount' => '39.00',
            ],
        ];
    }

    public function renewHost(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'billingcycle' => $billingCycle,
                'invoice_id' => 700000 + $hostId,
            ],
        ];
    }

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $invoiceId = 700000 + $hostId;

        return [
            'upstream_invoice_id' => $invoiceId,
            'renew_response' => $this->renewHost($supplier, $hostId, $billingCycle),
            'fund_response' => [
                'status' => 200,
                'data' => [
                    'invoice_id' => $invoiceId,
                    'paid' => true,
                ],
            ],
            'host_detail' => $this->hostPayload($hostId),
        ];
    }

    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'fund_response' => [
                'status' => 200,
                'data' => [
                    'invoice_id' => $upstreamInvoiceId,
                    'paid' => true,
                ],
            ],
            'host_detail' => $this->hostPayload($hostId),
        ];
    }

    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        $services = [];
        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            $hostId = (int) ($item['upstream_host_id'] ?? $item['host_id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $services[$serviceId] = [
                'host' => $this->hostPayload($hostId > 0 ? $hostId : $serviceId),
                'runtime' => [
                    'power_state' => 'running',
                    'cpu_usage' => 3,
                    'memory_usage' => 21,
                    'traffic_usage' => 8,
                ],
            ];
        }

        return [
            'jwt' => $this->login($supplier),
            'services' => $services,
        ];
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host' => $this->hostPayload($hostId),
            ],
        ];
    }

    public function getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'url' => 'https://demo.invalid/novnc?token=demo-vnc-token-'.$hostId,
                'token' => 'demo-vnc-token-'.$hostId,
                'expires_in' => 300,
            ],
        ];
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'action' => trim($action),
                'power_state' => trim($action) === 'stop' ? 'stopped' : 'running',
            ],
        ];
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'type' => $type,
                'power_state' => 'running',
            ],
        ];
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'systems' => [
                    ['id' => 'centos-9', 'name' => 'CentOS Stream 9'],
                    ['id' => 'debian-12', 'name' => 'Debian 12'],
                ],
            ],
        ];
    }

    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'reset' => true,
            ],
        ];
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'host_id' => $hostId,
                'os_id' => $osId,
                'queued' => true,
            ],
        ];
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'modules' => ['vnc', 'power', 'reinstall', 'reset_password', 'nat', 'security_group'],
            ],
        ];
    }

    public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, ?string $jwt = null): string
    {
        return '<div data-demo-module="'.htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8').'">Demo module page</div>';
    }

    public function submitCustomModuleAction(Supplier $supplier, string $endpoint, array $payload, ?string $jwt = null): array
    {
        return [
            'status' => 200,
            'data' => [
                'endpoint' => $endpoint,
                'accepted' => true,
            ],
        ];
    }

    private function catalogProduct(int $id, string $name, string $type, string $price, int $stock): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'type_label' => $type === 'server' ? '独立服务器' : '云服务器',
            'description' => 'Demo 上游商品，仅用于插件开发验证。',
            'billingcycle' => 'monthly',
            'product_price' => $price,
            'monthly_price' => $price,
            'setup_fee' => '0.00',
            'allow_qty' => 1,
            'stock_control' => 1,
            'qty' => $stock,
            'stock' => $stock,
            'first_group_name' => 'Demo 产品',
            'group_name' => $type === 'server' ? '独立服务器' : '云服务器',
            'group_label' => 'Demo 产品 / '.($type === 'server' ? '独立服务器' : '云服务器'),
        ];
    }

    private function hostPayload(int $hostId): array
    {
        return [
            'id' => $hostId,
            'name' => 'demo-host-'.$hostId,
            'domainstatus' => 'Active',
            'status' => 'Active',
            'power_state' => 'running',
            'dedicated_ip' => '203.0.113.'.($hostId % 200 + 1),
            'assigned_ips' => ['203.0.113.'.($hostId % 200 + 1)],
            'username' => 'root',
            'password' => '',
            'os' => 'Debian 12',
            'due_time' => now()->addMonth()->toDateTimeString(),
            'connection' => [
                'hostname' => '203.0.113.'.($hostId % 200 + 1),
                'username' => 'root',
                'port' => 22,
            ],
        ];
    }

    private function supplierId(Supplier $supplier): int
    {
        return max(0, (int) ($supplier->id ?? 0));
    }

    private function resolveRegion(array $config): string
    {
        $region = trim((string) ($config['demo_region'] ?? ''));

        return $region !== '' ? $region : 'ap-demo-1';
    }
}
