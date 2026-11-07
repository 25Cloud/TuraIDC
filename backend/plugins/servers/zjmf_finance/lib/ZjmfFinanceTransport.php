<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\Support\WebSessionCookieParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ZjmfFinanceTransport
{
    public function __construct(
        private readonly HostingPanelApiTransport $transport,
        private readonly ZjmfAuthManager $authManager,
        private readonly ?WebSessionCookieParser $webSessionCookieParser = null,
        private readonly ?ZjmfCapabilityMemory $capabilityMemory = null,
    ) {}

    public function login(Supplier $supplier): string
    {
        return $this->authManager->login($supplier);
    }

    public function refreshJwt(Supplier $supplier): string
    {
        return $this->authManager->refreshJwt($supplier);
    }

    public function loginResponse(Supplier $supplier): array
    {
        return $this->authManager->loginResponse($supplier);
    }

    public function getUserProfile(Supplier $supplier): array
    {
        // /v1/user 只存在于 3.7.6 及更早；3.8.0 起整套 /v1 被删。
        // 拿不到时用 /cart/credit 拼一个等价结构（余额 + 货币），够供应商卡片与余额同步用。
        if ($this->profile($supplier)->allowsV1()) {
            try {
                $response = $this->get($supplier, '/v1/user', $this->login($supplier));
                $data = $response['data'] ?? null;

                if (is_array($data) && isset($data['client']) && is_array($data['client'])) {
                    return $data;
                }
            } catch (\Throwable $exception) {
                if (! str_contains($exception->getMessage(), '404')) {
                    throw $exception;
                }
            }
        }

        $balance = $this->getBalance($supplier);

        return [
            'client' => $balance['client'],
            'currency' => $balance['currency'],
            'source' => 'cart/credit',
        ];
    }

    public function getBalance(Supplier $supplier): array
    {
        $response = $this->get($supplier, '/cart/credit', $this->login($supplier));
        $data = $response['data'] ?? null;

        if (! is_array($data) || ! array_key_exists('credit', $data)) {
            throw new BusinessException((string) ($response['msg'] ?? '获取上游余额失败'), 42200);
        }

        return [
            'balance' => (string) $data['credit'],
            'client' => ['credit' => (string) $data['credit']],
            'currency' => is_array($data['currency'] ?? null) ? $data['currency'] : [],
        ];
    }

    public function profile(Supplier $supplier): ZjmfCompatibilityProfile
    {
        return ZjmfCompatibilityProfile::fromSupplier($supplier);
    }

    /**
     * 读上游账单详情。
     *
     * 只有 3.7.6 及更早有 /v1/invoices/{id}；3.8.0 起整套 /v1 被删，
     * 这里返回 null 表示「读不到」，由调用方改用 /apply_credit 的结果判断支付状态。
     *
     * @return array<string, mixed>|null
     */
    public function fetchInvoice(Supplier $supplier, int $invoiceId, ?string $jwt = null): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        if (! $this->profile($supplier)->allowsV1()) {
            return null;
        }

        try {
            $meta = $this->requestWithMeta(
                $supplier,
                'GET',
                "/v1/invoices/{$invoiceId}",
                [],
                $this->resolveJwt($supplier, $jwt)
            );
        } catch (\Throwable $exception) {
            if (! str_contains($exception->getMessage(), '404')) {
                Log::warning('[ZJMF 财务接口] 读取上游账单失败', [
                    'supplier_id' => (int) $supplier->id,
                    'upstream_invoice_id' => $invoiceId,
                    'message' => $exception->getMessage(),
                ]);
            }

            return null;
        }

        $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];

        return (int) ($response['status'] ?? 0) === 200 ? ZjmfUpstreamResponse::withHttpCode($meta) : null;
    }

    /**
     * 用供应商余额支付上游账单。
     *
     * 候选顺序由供应商档位决定：
     * - /apply_credit            购物车结算页那条，3.7.6 → 3.8.0 都在，新版首选
     * - /v1/invoices/{id}/fund   只有老版本有，老站首选
     *
     * 账单已支付时上游回 400/406「账单已处理」，这里归一成 already_paid 成功返回，
     * 让幂等重试能把「已经付过」当成目标状态而不是错误。
     *
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function payInvoiceWithCredit(
        Supplier $supplier,
        int $invoiceId,
        ?string $jwt = null,
        array $headers = [],
        array $query = [],
    ): array {
        if ($invoiceId <= 0) {
            throw new BusinessException('上游账单编号无效，无法支付');
        }

        $jwt = $this->resolveJwt($supplier, $jwt);
        $errors = [];

        foreach ($this->profile($supplier)->invoicePayOrder() as $channel) {
            try {
                $meta = $channel === ZjmfCompatibilityProfile::INVOICE_PAY_V1_FUND
                    ? $this->requestWithMeta(
                        $supplier,
                        'POST',
                        "/v1/invoices/{$invoiceId}/fund",
                        [],
                        $jwt,
                        $headers,
                        $query
                    )
                    : $this->requestWithMeta(
                        $supplier,
                        'POST',
                        '/apply_credit',
                        ['invoiceid' => $invoiceId, 'use_credit' => 1, 'enough' => 1],
                        $jwt,
                        array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']),
                        $query
                    );
            } catch (\Throwable $exception) {
                $errors[$channel] = $exception->getMessage();

                continue;
            }

            $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];
            $status = (int) ($response['status'] ?? 0);
            if (in_array($status, [200, 1001], true)) {
                return array_merge(ZjmfUpstreamResponse::withHttpCode($meta), ['payment_channel' => $channel]);
            }

            $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
            if ($this->isInvoiceAlreadySettledMessage($message)) {
                return array_merge(ZjmfUpstreamResponse::withHttpCode($meta), [
                    'status' => 200,
                    'already_paid' => true,
                    'payment_channel' => $channel,
                ]);
            }

            $errors[$channel] = $message !== '' ? $message : '上游返回异常';
        }

        $labels = [
            ZjmfCompatibilityProfile::INVOICE_PAY_APPLY_CREDIT => 'apply_credit',
            ZjmfCompatibilityProfile::INVOICE_PAY_V1_FUND => 'v1/invoices/fund',
        ];
        $parts = [];
        foreach ($errors as $channel => $message) {
            $parts[] = ($labels[$channel] ?? $channel).'：'.$message;
        }

        throw new BusinessException('使用供应商余额支付上游账单失败（'.implode('；', $parts).'），请稍后重试');
    }

    private function isInvoiceAlreadySettledMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['已处理', '已支付', '已付款', 'paid', 'already'] as $needle) {
            if (str_contains(strtolower($message), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        return $this->transport->getProductCatalog($supplier);
    }

    public function fetchRealConfigOptions(Supplier $supplier, int $productId): array
    {
        return $this->transport->fetchRealConfigOptions($supplier, $productId);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        return $this->transport->fetchBatchProductConfigOptions($supplier, $productIds, $chunkSize);
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        return $this->transport->fetchBatchProductStocks($supplier, $productIds, $chunkSize);
    }

    public function resolveSupplierRootUrl(Supplier $supplier): string
    {
        return $this->transport->resolveSupplierRootUrl($supplier);
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $response = $this->get($supplier, '/host/header', $this->resolveJwt($supplier, $jwt), [
            'host_id' => $hostId,
            'source' => 'API',
        ]);

        return $this->normalizeHostDetailResponse($response);
    }

    /**
     * 上游「续费页」接口：返回该主机可用的续费周期列表（data.cycle），
     * 是上游对「这台主机还能按哪些周期续费」的官方口径。
     */
    public function getRenewPage(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->get($supplier, '/host/renewpage', $this->resolveJwt($supplier, $jwt), [
            'hostid' => $hostId,
        ]);
    }

    /**
     * @return array{response: array, headers: array<int, string>, http_code: int, content_type: string}
     */
    public function getHostDetailWithMeta(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $meta = $this->requestWithMeta(
            $supplier,
            'GET',
            '/host/header',
            [],
            $this->resolveJwt($supplier, $jwt),
            [],
            [
                'host_id' => $hostId,
                'source' => 'API',
            ]
        );
        $meta['response'] = $this->normalizeHostDetailResponse((array) ($meta['response'] ?? []));

        return $meta;
    }

    private function normalizeHostDetailResponse(array $response): array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : [];
        $host = $this->normalizeHostDetailPayload($payload);

        if ($host !== []) {
            $response['data'] = [
                ...$payload,
                'host' => $host,
            ];
        }

        return $response;
    }

    /**
     * Converts the ZJMF same-system host/header payload to the plugin's host shape.
     */
    public function normalizeHostDetailPayload(array $payload): array
    {
        $host = is_array($payload['host_data'] ?? null)
            ? $payload['host_data']
            : (is_array($payload['host'] ?? null) ? $payload['host'] : []);

        if ($host === []) {
            return [];
        }

        $productId = (int) ($host['product_id'] ?? 0);
        if ($productId <= 0) {
            $productId = (int) ($host['productid'] ?? $host['pid'] ?? 0);
        }

        $productName = trim((string) ($host['product_name'] ?? ''));
        if ($productName === '') {
            $productName = trim((string) ($host['productname'] ?? ''));
        }

        $host['product_id'] = $productId;
        $host['product_name'] = $productName;

        if (is_array($payload['config_options'] ?? null)) {
            $host['config_option'] = $this->normalizeHostConfigOptions($payload['config_options']);
        }

        return $host;
    }

    public function getHostUpgradeConfigOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->getHostUpgradeConfigOptions($supplier, $hostId, $this->resolveJwt($supplier, $jwt));
    }

    public function previewHostConfigUpgrade(Supplier $supplier, int $hostId, array $configOption, ?string $jwt = null): array
    {
        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig", [
            'configoption' => $configOption,
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function checkoutHostConfigUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/checkout", [], $this->resolveJwt($supplier, $jwt));
    }

    public function getHostUpgradePromoPreview(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->put($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [
            'promo_code' => trim($promoCode),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function removeHostUpgradePromoCode(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->request($supplier, 'DELETE', "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [], $this->resolveJwt($supplier, $jwt));
    }

    public function getHostUpgradeOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->get($supplier, "/v1/hosts/{$hostId}/actions/upgrade", $this->resolveJwt($supplier, $jwt));
    }

    public function previewHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
    {
        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade", [
            'product_id' => $productId,
            'billingcycle' => trim($billingCycle),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function applyHostUpgradePromoCode(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->put($supplier, "/v1/hosts/{$hostId}/actions/upgrade/promo", [
            'promo_code' => trim($promoCode),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function checkoutHostUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade/checkout", [], $this->resolveJwt($supplier, $jwt));
    }

    public function buyFlowPacket(Supplier $supplier, string $rootUrl, int $flowPacketId, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== '' ? trim($jwt) : '';
        $headers = [];

        if ($resolvedJwt === '') {
            $webSessionCookie = $this->resolveSupplierWebSessionCookie($supplier);
            if ($webSessionCookie !== '') {
                $headers[] = 'Cookie: '.$webSessionCookie;
            } else {
                $resolvedJwt = $this->login($supplier);
            }
        }

        $rootUrl = rtrim($rootUrl, '/');
        $payload = [
            'flow_packet_id' => $flowPacketId,
            'service_id' => $hostId,
            'id' => $hostId,
        ];

        $pageUrl = $rootUrl.'/servicedetail?'.http_build_query([
            'id' => $hostId,
            'action' => 'flowpacket',
        ]);

        $pageResponse = $this->post($supplier, $pageUrl, $payload, $resolvedJwt !== '' ? $resolvedJwt : null, $headers);
        if ((int) ($pageResponse['code'] ?? -1) === 0) {
            return $pageResponse;
        }

        $legacyResponse = $this->post($supplier, $rootUrl.'/dcim/buy_flow_packet', $payload, $resolvedJwt !== '' ? $resolvedJwt : null, $headers);
        if ((int) ($legacyResponse['code'] ?? -1) === 0) {
            return $legacyResponse;
        }

        return $pageResponse;
    }

    public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'POST', $uri, $payload, $jwt, $headers, $query);
    }

    /**
     * @return array<int, array{id: string, name: string, description: string}>
     */
    public function getTicketDepartments(Supplier $supplier, ?string $jwt = null): array
    {
        $response = $this->get($supplier, '/ticket/department', $jwt);
        $status = (int) ($response['status'] ?? $response['code'] ?? 0);
        if ($status !== 200) {
            throw new BusinessException((string) ($response['msg'] ?? '获取上游工单部门失败'), 42200);
        }

        $items = $response['data'] ?? null;
        if (! is_array($items)) {
            throw new BusinessException('上游工单部门响应格式异常', 42200);
        }

        return array_values(array_filter(array_map(static function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($id === '' || $name === '') {
                return null;
            }

            return [
                'id' => $id,
                'name' => $name,
                'description' => trim((string) ($item['description'] ?? '')),
            ];
        }, $items)));
    }

    public function registerDownstreamCallback(
        Supplier $supplier,
        int $upstreamHostId,
        int $upstreamProductId,
        int $downstreamId,
        string $downstreamUrl,
        string $downstreamToken,
        ?string $jwt = null,
    ): array {
        return $this->post($supplier, '/host/setdownstream', [
            'id' => $upstreamHostId,
            'pid' => $upstreamProductId,
            'downstream_url' => rtrim($downstreamUrl, '/'),
            'downstream_token' => $downstreamToken,
            'downstream_id' => $downstreamId,
        ], $jwt);
    }

    /**
     * 上传工单附件到上游图片接口，返回上游保存的文件名（savename）。
     * 本地路径校验失败、文件句柄打开失败或上游返回失败/缺少 savename 时一律抛出 BusinessException，
     * 保证调用方不会因空返回值而静默丢失附件（与上游业务失败保持一致的失败语义）。
     *
     * @throws BusinessException 路径非法、文件不可读、上游请求失败或缺少 savename 时抛出
     */
    public function uploadTicketAttachment(Supplier $supplier, string $path): string
    {
        $root = realpath(storage_path('app'));
        if ($root === false) {
            throw new BusinessException('上游附件上传失败', 50000);
        }

        $absolutePath = realpath($root.'/'.ltrim(str_replace('\\', '/', $path), '/'));
        if ($absolutePath === false
            || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)
            || ! is_file($absolutePath)
        ) {
            throw new BusinessException('上游附件上传失败', 50000);
        }

        $jwt = $this->login($supplier);
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new BusinessException('上游附件上传失败', 50000);
        }

        try {
            $response = Http::timeout(30)
                ->withOptions($this->transport->httpClientOptions())
                ->withToken($jwt)
                ->attach('file', $handle, basename($absolutePath))
                ->post(rtrim((string) $supplier->api_url, '/').'/upload_image');
        } finally {
            fclose($handle);
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $businessOk = array_key_exists('status', $payload)
            ? (int) ($payload['status'] ?? 0) === 200
            : (int) ($payload['code'] ?? -1) === 0;
        if (! $response->successful() || ! $businessOk) {
            throw new BusinessException('上游附件上传失败', 50000);
        }

        $name = data_get($payload, 'data.savename') ?? data_get($payload, 'savename');
        if ($name === null || trim((string) $name) === '') {
            throw new BusinessException('上游附件上传失败', 50000);
        }

        return (string) $name;
    }

    public function get(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): array
    {
        return $this->request($supplier, 'GET', $uri, [], $jwt, $headers, $query);
    }

    public function getText(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): string
    {
        return $this->requestText($supplier, 'GET', $uri, [], $jwt, $headers, $query);
    }

    public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
    {
        $resolvedJwt = $this->resolveRequestJwt($supplier, $jwt, '', $headers);
        $responses = $this->transport->parallelGet(
            $supplier,
            $requests,
            $resolvedJwt,
            $this->withBearerAuthorization($resolvedJwt, $headers)
        );

        if ($this->containsUnauthorizedResponse($responses)) {
            $this->authManager->forget($supplier);
        }

        return $responses;
    }

    public function put(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'PUT', $uri, $payload, $jwt, $headers, $query);
    }

    public function delete(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'DELETE', $uri, $payload, $jwt, $headers, $query);
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
        $resolvedJwt = $this->resolveRequestJwt($supplier, $jwt, $uri, $headers);

        return $this->transport->requestText(
            $supplier,
            $method,
            $uri,
            $payload,
            $resolvedJwt,
            $this->withBearerAuthorization($resolvedJwt, $headers),
            $query
        );
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
        $resolvedJwt = $this->resolveRequestJwt($supplier, $jwt, $uri, $headers);
        $this->guardV1($supplier, $uri);

        try {
            $response = $this->transport->request(
                $supplier,
                $method,
                $uri,
                $payload,
                $resolvedJwt,
                $this->withBearerAuthorization($resolvedJwt, $headers),
                $query
            );
        } catch (\Throwable $exception) {
            $this->handleV1Failure($supplier, $uri, $exception);
        }

        $this->authManager->forgetIfUnauthorizedResponse($supplier, 0, $response, $resolvedJwt);

        return $response;
    }

    /**
     * @return array{response: array, headers: array<int, string>, http_code: int, content_type: string}
     */
    public function requestWithMeta(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): array {
        $resolvedJwt = $this->resolveRequestJwt($supplier, $jwt, $uri, $headers);
        $this->guardV1($supplier, $uri);

        try {
            $meta = $this->transport->requestWithMeta(
                $supplier,
                $method,
                $uri,
                $payload,
                $resolvedJwt,
                $this->withBearerAuthorization($resolvedJwt, $headers),
                $query
            );
        } catch (\Throwable $exception) {
            $this->handleV1Failure($supplier, $uri, $exception);
        }

        $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];
        $this->authManager->forgetIfUnauthorizedResponse(
            $supplier,
            (int) ($meta['http_code'] ?? 0),
            $response,
            $resolvedJwt
        );

        return $meta;
    }

    private function memory(): ZjmfCapabilityMemory
    {
        return $this->capabilityMemory ?? new ZjmfCapabilityMemory;
    }

    /**
     * /v1/* 在 3.8.0 起被整体移除。供应商档位明确关掉、或这个供应商已经实测过 404 时，
     * 直接给可操作的提示，不再打一次必然失败的请求。
     */
    private function guardV1(Supplier $supplier, string $uri): void
    {
        if (! $this->isV1Uri($uri)) {
            return;
        }

        $profile = $this->profile($supplier);
        if (! $profile->allowsV1()) {
            throw $profile->v1UnavailableMessage($this->v1ActionLabel($uri));
        }

        if ($this->memory()->recall((int) $supplier->id, 'v1') === 'unavailable') {
            throw $profile->v1UnavailableMessage($this->v1ActionLabel($uri));
        }
    }

    /**
     * /v1 打回 404 时记下「本供应商没有 /v1」，并把原始异常换成能照着做的提示。
     *
     * @phpstan-return never
     */
    private function handleV1Failure(Supplier $supplier, string $uri, \Throwable $exception): void
    {
        if (! $this->isV1Uri($uri) || ! str_contains($exception->getMessage(), '404')) {
            throw $exception;
        }

        $this->memory()->remember((int) $supplier->id, 'v1', 'unavailable');

        Log::warning('[ZJMF 财务接口] 上游 /v1 接口不存在，已按无 /v1 处理', [
            'supplier_id' => (int) $supplier->id,
            'uri' => $uri,
        ]);

        throw $this->profile($supplier)->v1UnavailableMessage($this->v1ActionLabel($uri));
    }

    private function isV1Uri(string $uri): bool
    {
        return str_starts_with(ltrim(trim($uri)), '/v1/');
    }

    private function v1ActionLabel(string $uri): string
    {
        $uri = strtolower($uri);

        return match (true) {
            str_contains($uri, 'upgradeconfig') => '升级配置',
            str_contains($uri, 'upgrade') => '升级套餐',
            str_contains($uri, 'invoices') => '支付或读取上游账单',
            str_contains($uri, 'user') => '读取会员资料',
            default => '执行该操作',
        };
    }

    private function resolveJwt(Supplier $supplier, ?string $jwt): string
    {
        $jwt = trim((string) $jwt);

        return $jwt !== '' ? $jwt : $this->login($supplier);
    }

    private function normalizeHostConfigOptions(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'id' => (int) ($item['id'] ?? 0),
                'key' => (string) ($item['name_k'] ?? $item['key'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'type' => (int) ($item['option_type'] ?? $item['type'] ?? 0),
                'value' => (string) ($item['sub_name'] ?? $item['value'] ?? ''),
                'code' => (string) ($item['code'] ?? ''),
                'os_group' => (string) ($item['os_group'] ?? ''),
            ];
        }

        return $normalized;
    }

    private function resolveRequestJwt(Supplier $supplier, ?string $jwt, string $uri, array $headers = []): ?string
    {
        $jwt = trim((string) $jwt);
        if ($jwt !== '') {
            return $jwt;
        }

        if ($this->isLoginEndpoint($uri) || $this->hasCookieHeader($headers) || $this->hasAuthorizationHeader($headers)) {
            return null;
        }

        return $this->login($supplier);
    }

    private function withBearerAuthorization(?string $jwt, array $headers): array
    {
        $jwt = trim((string) $jwt);
        if ($jwt === '' || $this->hasAuthorizationHeader($headers)) {
            return $headers;
        }

        return array_merge($headers, ['Authorization: Bearer '.$jwt]);
    }

    private function hasAuthorizationHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim((string) $header)), 'authorization:')) {
                return true;
            }
        }

        return false;
    }

    private function isLoginEndpoint(string $uri): bool
    {
        $uri = strtolower(trim($uri));

        return $uri === '/zjmf_api_login'
            || str_contains($uri, '/zjmf_api_login?');
    }

    private function hasCookieHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim((string) $header)), 'cookie:')) {
                return true;
            }
        }

        return false;
    }

    private function resolveSupplierWebSessionCookie(Supplier $supplier): string
    {
        // 优先使用加密存储的会话 Cookie（supplierWithRuntimeCredentials 解密注入），
        // 回退历史明文 notes，兼容存量供应商。
        $secretCookie = trim((string) ($supplier->getAttribute('web_session_cookie') ?? ''));
        if ($secretCookie !== '') {
            return $secretCookie;
        }

        return $this->webSessionCookieParser()->parse((string) ($supplier->notes ?? ''));
    }

    private function webSessionCookieParser(): WebSessionCookieParser
    {
        return $this->webSessionCookieParser ?? new WebSessionCookieParser;
    }

    private function containsUnauthorizedResponse(array $responses): bool
    {
        foreach ($responses as $response) {
            if (! is_array($response)) {
                continue;
            }

            $statusCode = (int) ($response['status_code'] ?? 0);
            $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
            $businessStatus = (int) ($payload['status'] ?? $payload['code'] ?? $payload['status_code'] ?? 0);

            if ($statusCode === 401 || $businessStatus === 401) {
                return true;
            }
        }

        return false;
    }
}
