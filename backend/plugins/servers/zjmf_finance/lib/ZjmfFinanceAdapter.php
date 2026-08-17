<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

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
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;

final class ZjmfFinanceAdapter implements ProvidesConsoleAccess, ProvidesConsoleCatalog, ProvidesConsoleNetwork, ProvidesConsoleRuntime, ProvidesConsoleSecurity, ProvidesProvisioning, ProvidesRenewal, ProvidesScheduledAuthRefresh, ProvidesStatusSync
{
    private readonly ZjmfFinanceTransport $transport;

    private readonly ZjmfCatalogService $catalogService;

    private readonly ZjmfProvisionService $provisionService;

    private readonly ZjmfRenewService $renewService;

    private readonly ZjmfStatusService $statusService;

    private readonly ZjmfConsoleService $consoleService;

    private readonly ZjmfNetworkService $networkService;

    private readonly ZjmfSecurityService $securityService;

    public function __construct(
        HostingPanelApiTransport $transport,
        ZjmfCloudConfigTemplate $cloudConfigTemplate,
        private readonly ZjmfProductTypeMapper $productTypeMapper = new ZjmfProductTypeMapper,
        ?ZjmfFinanceTransport $zjmfTransport = null,
        ?ZjmfCatalogService $catalogService = null,
        ?ZjmfProvisionService $provisionService = null,
        ?ZjmfRenewService $renewService = null,
        ?ZjmfStatusService $statusService = null,
        ?ZjmfConsoleService $consoleService = null,
        ?ZjmfNetworkService $networkService = null,
        ?ZjmfSecurityService $securityService = null,
    ) {
        $this->transport = $zjmfTransport ?? new ZjmfFinanceTransport(
            $transport,
            new ZjmfAuthManager($transport)
        );
        $this->catalogService = $catalogService ?? new ZjmfCatalogService(
            $this->transport,
            $cloudConfigTemplate,
            $this->productTypeMapper
        );
        $this->provisionService = $provisionService ?? new ZjmfProvisionService($this->transport);
        $this->renewService = $renewService ?? new ZjmfRenewService($this->transport);
        $this->statusService = $statusService ?? new ZjmfStatusService($this->transport);
        $this->consoleService = $consoleService ?? new ZjmfConsoleService($this->transport);
        $this->networkService = $networkService ?? new ZjmfNetworkService($this->transport, $this->consoleService);
        $this->securityService = $securityService ?? new ZjmfSecurityService($this->transport);
    }

    public function login(Supplier $supplier): string
    {
        return $this->transport->login($supplier);
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->transport->refreshJwt($supplier);
    }

    public function loginResponse(Supplier $supplier): array
    {
        return $this->transport->loginResponse($supplier);
    }

    public function getUserProfile(Supplier $supplier): array
    {
        return $this->transport->getUserProfile($supplier);
    }

    public function getBalance(Supplier $supplier): array
    {
        return $this->transport->getBalance($supplier);
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        return $this->catalogService->getProductCatalog($supplier);
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        return $this->catalogService->fetchRealConfigOptions($supplier, $productId);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        return $this->catalogService->fetchBatchProductConfigOptions($supplier, $productIds, $chunkSize);
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        return $this->catalogService->fetchBatchProductStocks($supplier, $productIds, $chunkSize);
    }

    public function getProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        return $this->catalogService->getProductConfigTemplate($supplier, $productId);
    }

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        return $this->provisionService->provisionOrder($order, $supplier, $existingService);
    }

    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return $this->provisionService->getProductProvisionConfig($supplier, $productId);
    }

    public function renewHost(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        return $this->renewService->renewHost($supplier, $hostId, $billingCycle);
    }

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        return $this->renewService->renewServiceInvoice($supplier, $hostId, $billingCycle);
    }

    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        return $this->renewService->recoverRenewInvoice($supplier, $hostId, $upstreamInvoiceId);
    }

    public function recoverRenewInvoiceWithContext(
        Supplier $supplier,
        int $hostId,
        int $upstreamInvoiceId,
        array $recoveryContext = [],
    ): ?array {
        return $this->renewService->recoverRenewInvoiceWithContext($supplier, $hostId, $upstreamInvoiceId, $recoveryContext);
    }

    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        return $this->statusService->syncServiceStatuses($supplier, $items, $chunkSize);
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->consoleService->getHostDetail($supplier, $hostId, $jwt);
    }

    public function getVncUrl(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->consoleService->getVncUrl($supplier, $hostId, $jwt);
    }

    public function powerAction(Supplier $supplier, int $hostId, string $action, ?string $jwt = null): array
    {
        return $this->consoleService->powerAction($supplier, $hostId, $action, $jwt);
    }

    public function getModuleStatus(Supplier $supplier, int $hostId, string $type = 'host', ?string $jwt = null): array
    {
        return $this->consoleService->getModuleStatus($supplier, $hostId, $type, $jwt);
    }

    public function getReinstallOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->consoleService->getReinstallOptions($supplier, $hostId, $jwt);
    }

    public function resetPassword(Supplier $supplier, int $hostId, string $password, ?string $jwt = null): array
    {
        return $this->consoleService->resetPassword($supplier, $hostId, $password, $jwt);
    }

    public function reinstall(Supplier $supplier, int $hostId, string $osId, ?string $jwt = null): array
    {
        return $this->consoleService->reinstall($supplier, $hostId, $osId, $jwt);
    }

    public function getSupportedModules(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->consoleService->getSupportedModules($supplier, $hostId, $jwt);
    }

    public function getMonitorChart(Supplier $supplier, int $hostId, array $query, ?string $jwt = null): array
    {
        return $this->consoleService->getMonitorChart($supplier, $hostId, $query, $jwt);
    }

    public function getMonitorCharts(Supplier $supplier, int $hostId, array $queries, ?string $jwt = null): array
    {
        return $this->consoleService->getMonitorCharts($supplier, $hostId, $queries, $jwt);
    }

    public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, ?string $jwt = null): string
    {
        return $this->consoleService->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt);
    }

    public function getHostUpgradeConfigOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->getHostUpgradeConfigOptions($supplier, $hostId, $jwt);
    }

    public function previewHostConfigUpgrade(Supplier $supplier, int $hostId, array $configOption, ?string $jwt = null): array
    {
        return $this->networkService->previewHostConfigUpgrade($supplier, $hostId, $configOption, $jwt);
    }

    public function checkoutHostConfigUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->checkoutHostConfigUpgrade($supplier, $hostId, $jwt);
    }

    public function getHostUpgradePromoPreview(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->networkService->getHostUpgradePromoPreview($supplier, $hostId, $promoCode, $jwt);
    }

    public function removeHostUpgradePromoCode(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->removeHostUpgradePromoCode($supplier, $hostId, $jwt);
    }

    public function getHostUpgradeOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->getHostUpgradeOptions($supplier, $hostId, $jwt);
    }

    public function previewHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
    {
        return $this->networkService->previewHostUpgrade($supplier, $hostId, $productId, $billingCycle, $jwt);
    }

    public function applyHostUpgradePromoCode(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->networkService->applyHostUpgradePromoCode($supplier, $hostId, $promoCode, $jwt);
    }

    public function checkoutHostUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->checkoutHostUpgrade($supplier, $hostId, $jwt);
    }

    public function buyFlowPacket(Supplier $supplier, string $rootUrl, int $flowPacketId, int $hostId, ?string $jwt = null): array
    {
        return $this->networkService->buyFlowPacket($supplier, $rootUrl, $flowPacketId, $hostId, $jwt);
    }

    public function fundInvoice(Supplier $supplier, int $invoiceId, ?string $jwt = null, string $action = '支付上游账单'): array
    {
        return $this->networkService->fundInvoice($supplier, $invoiceId, $jwt, $action);
    }

    public function purchaseTrafficPackage(
        Supplier $supplier,
        int $hostId,
        string $mode,
        array $configOption,
        int $flowPacketId,
        string $rootUrl,
        ?string $jwt = null,
    ): array {
        return $this->networkService->purchaseTrafficPackage($supplier, $hostId, $mode, $configOption, $flowPacketId, $rootUrl, $jwt);
    }

    public function purchaseHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, string $promoCode = '', ?string $jwt = null): array
    {
        return $this->networkService->purchaseHostUpgrade($supplier, $hostId, $productId, $billingCycle, $promoCode, $jwt);
    }

    public function submitCustomModuleAction(Supplier $supplier, string $endpoint, array $payload, ?string $jwt = null): array
    {
        return $this->securityService->submitCustomModuleAction($supplier, $endpoint, $payload, $jwt);
    }

    public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
    {
        return $this->consoleService->getCustomModuleActionEndpoint($supplier, $hostId);
    }

    public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->transport->post($supplier, $uri, $payload, $jwt, $headers, $query);
    }

    public function get(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): array
    {
        return $this->transport->get($supplier, $uri, $jwt, $query, $headers);
    }

    public function getText(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): string
    {
        return $this->transport->getText($supplier, $uri, $jwt, $query, $headers);
    }

    public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
    {
        return $this->transport->parallelGet($supplier, $requests, $jwt, $headers);
    }

    public function put(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->transport->put($supplier, $uri, $payload, $jwt, $headers, $query);
    }

    public function delete(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->transport->delete($supplier, $uri, $payload, $jwt, $headers, $query);
    }

    public function requestText(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): string {
        return $this->transport->requestText($supplier, $method, $uri, $payload, $jwt, $headers, $query);
    }

    public function request(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): array {
        return $this->transport->request($supplier, $method, $uri, $payload, $jwt, $headers, $query);
    }

    /**
     * @return array<int, string>
     */
    public static function explicitlyDeclaredBridgeMethods(): array
    {
        return [
            'login',
            'refreshJwt',
            'loginResponse',
            'getUserProfile',
            'getBalance',
            'getProductCatalog',
            'getProductConfigTemplate',
            'fetchRealConfigOptions',
            'fetchBatchProductConfigOptions',
            'fetchBatchProductStocks',
            'provisionOrder',
            'getProductProvisionConfig',
            'renewHost',
            'renewServiceInvoice',
            'recoverRenewInvoice',
            'recoverRenewInvoiceWithContext',
            'syncServiceStatuses',
            'getHostDetail',
            'getVncUrl',
            'powerAction',
            'getModuleStatus',
            'getReinstallOptions',
            'resetPassword',
            'reinstall',
            'getSupportedModules',
            'getMonitorChart',
            'getMonitorCharts',
            'fetchCustomModulePage',
            'getHostUpgradeConfigOptions',
            'previewHostConfigUpgrade',
            'checkoutHostConfigUpgrade',
            'getHostUpgradePromoPreview',
            'removeHostUpgradePromoCode',
            'getHostUpgradeOptions',
            'previewHostUpgrade',
            'applyHostUpgradePromoCode',
            'checkoutHostUpgrade',
            'buyFlowPacket',
            'fundInvoice',
            'purchaseTrafficPackage',
            'purchaseHostUpgrade',
            'submitCustomModuleAction',
            'getCustomModuleActionEndpoint',
            'post',
            'get',
            'getText',
            'parallelGet',
            'put',
            'delete',
            'requestText',
            'request',
        ];
    }
}
