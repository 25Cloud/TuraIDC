<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\Upstream\ProviderKey;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

final class ZjmfProvisionService
{
    private const UPSTREAM_HTTP_CODE_META_KEY = '_zjmf_upstream_http_code';

    private const RANGE_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    private const TYPE_FIELD_MAP = [
        4 => 'ip_num',
        5 => 'os',
        6 => 'cpu',
        7 => 'cpu',
        8 => 'memory',
        9 => 'memory',
        10 => 'bw',
        11 => 'bw',
        12 => 'area',
        13 => 'system_disk_size',
        14 => 'system_disk_size',
        16 => 'cpu',
        17 => 'memory',
        18 => 'bw',
        19 => 'system_disk_size',
    ];

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
        private ?PluginBindingResolver $bindingResolver = null,
    ) {}

    public function provisionOrder(Order $order, Supplier $supplier, ?Service $existingService = null): array
    {
        $product = $order->product;
        if (! $product instanceof Product) {
            throw new BusinessException('商品信息不存在，无法自动开通');
        }

        if ($existingService && $this->resolveExistingHostId($existingService) > 0) {
            $existingHostId = $this->resolveExistingHostId($existingService);
            $existingProvisionData = (array) ($existingService->provision_data ?? []);
            try {
                $jwt = $this->transport->login($supplier);
                $detailResponse = $this->responseWithHttpCode(
                    $this->transport->getHostDetailWithMeta($supplier, $existingHostId, $jwt)
                );
                $this->assertUpstreamSuccess($detailResponse, [200], '确认已创建的上游实例状态');
                $detailPayload = $this->extractPayload($detailResponse);
                $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
                $this->assertHostDetailPresent($hostDetail, $existingHostId);
                $callback = ['token' => null, 'id' => null];

                // 幂等回查除确认 host 存在外，还需校验其上游账单已支付：
                // 若 host 在欠费/待支付模式下仍返回详情，本地会把服务确认成 ACTIVE、订单 COMPLETED，
                // 而上游账单实际未扣款，造成收入缺口；账单未付时重试供应商余额支付。
                $existingInvoiceId = (int) ($existingProvisionData['upstream_invoice_id'] ?? 0);
                if ($existingInvoiceId > 0) {
                    $invoiceResponse = $this->responseWithHttpCode(
                        $this->transport->get($supplier, "/v1/invoices/{$existingInvoiceId}", $jwt)
                    );
                    $this->assertUpstreamSuccess($invoiceResponse, [200, 1000, 1001], '校验上游开通账单支付状态');
                    $invoicePayload = $this->extractPayload($invoiceResponse);
                    $upstreamStatus = strtolower(trim((string) ($invoicePayload['status'] ?? '')));

                    if ($upstreamStatus !== 'paid') {
                        $fundResponse = $this->transport->post($supplier, "/v1/invoices/{$existingInvoiceId}/fund", [], $jwt);
                        $this->assertUpstreamSuccess($fundResponse, [200, 1000, 1001], '重试供应商余额支付开通账单');
                    }
                }

                Log::info('[ZJMF 财务开通] 上游 host 已存在，跳过重复开通', [
                    'order_id' => $order->id,
                    'upstream_host_id' => $existingHostId,
                ]);

                return [
                    'requested_host' => (string) ($existingProvisionData['requested_host'] ?? ''),
                    'upstream_invoice_id' => (int) ($existingProvisionData['upstream_invoice_id'] ?? 0),
                    'upstream_host_ids' => $existingProvisionData['upstream_host_ids'] ?? [$existingHostId],
                    'upstream_host_id' => $existingHostId,
                    'host_detail' => $hostDetail,
                    'downstream_token' => $callback['token'] ?? null,
                    'downstream_id' => $callback['id'] ?? null,
                ];
            } catch (\Throwable $exception) {
                Log::warning('[ZJMF 财务开通] 上游 host 幂等回查失败，已停止重复开通', [
                    'order_id' => $order->id,
                    'upstream_host_id' => $existingHostId,
                    'message' => $exception->getMessage(),
                ]);

                throw new BusinessException('无法确认已创建的上游实例状态，已停止重复开通，请稍后重试或先核实上游实例');
            }
        }

        $startedAt = microtime(true);
        $latency = [
            'login_ms' => 0,
            'clear_cart_before_ms' => 0,
            'product_config_ms' => 0,
            'add_cart_ms' => 0,
            'checkout_ms' => 0,
            'apply_credit_ms' => 0,
            'find_host_ids_ms' => 0,
            'host_detail_ms' => 0,
            'clear_cart_after_ms' => 0,
        ];
        $result = 'failed';
        $requestedHost = '';
        $invoiceId = 0;
        $hostId = 0;
        $errorMessage = '';
        $errorClass = '';
        $jwt = null;
        $cartCookieJar = [];

        try {
            $stepStartedAt = microtime(true);
            $jwt = $this->transport->login($supplier);
            $latency['login_ms'] = $this->elapsedMilliseconds($stepStartedAt);

            $stepStartedAt = microtime(true);
            $this->clearCart($supplier, $jwt, $cartCookieJar);
            $latency['clear_cart_before_ms'] = $this->elapsedMilliseconds($stepStartedAt);

            $upstreamProductId = $this->resolveProductUpstreamProductId($product);

            $stepStartedAt = microtime(true);
            $productConfigResponse = $this->requestCartWithSession(
                $supplier,
                'GET',
                '/cart/get_product_config',
                [],
                $jwt,
                $cartCookieJar,
                [],
                ['pid' => $upstreamProductId]
            );
            $latency['product_config_ms'] = $this->elapsedMilliseconds($stepStartedAt);
            $this->assertUpstreamSuccess($productConfigResponse, [200], '读取上游商品配置');

            $payload = $this->buildCartPayload(
                $order,
                $product,
                $this->resolveCurrencyId($order, $product, $this->extractPayload($productConfigResponse), $upstreamProductId)
            );
            $requestedHost = (string) ($payload['host'] ?? '');

            $stepStartedAt = microtime(true);
            $addCartResponse = $this->addProductToCart($supplier, $jwt, $payload, $cartCookieJar);
            $latency['add_cart_ms'] = $this->elapsedMilliseconds($stepStartedAt);
            $this->assertUpstreamSuccess($addCartResponse, [200], '加入上游购物车');
            $cartPosition = $this->extractCartPosition($addCartResponse, $this->extractPayload($addCartResponse));

            $stepStartedAt = microtime(true);
            $checkoutResponse = $this->requestCartWithSession(
                $supplier,
                'POST',
                '/cart/settle',
                [
                    'pos' => [$cartPosition],
                    'checkout' => 1,
                ],
                $jwt,
                $cartCookieJar,
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ]
            );
            $latency['checkout_ms'] = $this->elapsedMilliseconds($stepStartedAt);
            $this->assertUpstreamSuccess($checkoutResponse, [200, 1001], '上游购物车结算');

            $checkoutPayload = $this->extractPayload($checkoutResponse);
            $invoiceId = $this->extractInvoiceId($checkoutResponse, $checkoutPayload);
            $hostIds = $this->extractHostIds($checkoutResponse, $checkoutPayload);
            $this->checkpointUpstreamProvision($existingService, $supplier, $product, $invoiceId, $hostIds, $requestedHost);

            if ($invoiceId <= 0) {
                throw new BusinessException('上游已接受结算，但未返回账单 ID');
            }

            if ($this->extractResponseStatus($checkoutResponse) !== 1001) {
                $stepStartedAt = microtime(true);
                $creditResponse = $this->requestCartWithSession(
                    $supplier,
                    'POST',
                    '/apply_credit',
                    [
                        'invoiceid' => $invoiceId,
                        'use_credit' => 1,
                        'enough' => 1,
                    ],
                    $jwt,
                    $cartCookieJar,
                    [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Accept: application/json',
                    ]
                );
                $latency['apply_credit_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->assertUpstreamSuccess($creditResponse, [200, 1001], '使用供应商余额支付上游账单');

                $creditPayload = $this->extractPayload($creditResponse);
                $hostIds = array_values(array_unique([
                    ...$hostIds,
                    ...$this->extractHostIds($creditResponse, $creditPayload),
                ]));
                $this->checkpointUpstreamProvision($existingService, $supplier, $product, $invoiceId, $hostIds, $requestedHost);
            }

            if ($hostIds === []) {
                $stepStartedAt = microtime(true);
                $hostIds = $this->findHostIdsForProvision($supplier, $jwt, $invoiceId, (string) $payload['host']);
                $latency['find_host_ids_ms'] = $this->elapsedMilliseconds($stepStartedAt);
                $this->checkpointUpstreamProvision($existingService, $supplier, $product, $invoiceId, $hostIds, $requestedHost);
            }

            if ($hostIds === []) {
                throw new BusinessException('上游已接受订单，但未返回已开通的产品 ID');
            }

            $hostId = (int) $hostIds[0];

            $stepStartedAt = microtime(true);
            $detailResponse = $this->responseWithHttpCode(
                $this->transport->getHostDetailWithMeta($supplier, $hostId, $jwt)
            );
            $latency['host_detail_ms'] = $this->elapsedMilliseconds($stepStartedAt);
            $this->assertUpstreamSuccess($detailResponse, [200], '读取上游产品详情');
            $detailPayload = $this->extractPayload($detailResponse);
            $hostDetail = is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
            $this->assertHostDetailPresent($hostDetail, $hostId);
            $callback = ['token' => null, 'id' => null];

            $result = 'success';

            return [
                'requested_host' => (string) $payload['host'],
                'upstream_invoice_id' => $invoiceId,
                'upstream_host_ids' => $hostIds,
                'upstream_host_id' => $hostId,
                'host_detail' => $hostDetail,
                'downstream_token' => $callback['token'] ?? null,
                'downstream_id' => $callback['id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $errorMessage = $exception->getMessage();
            $errorClass = $exception::class;

            throw $exception;
        } finally {
            $clearStartedAt = microtime(true);

            try {
                if (is_string($jwt) && trim($jwt) !== '') {
                    $this->clearCart($supplier, $jwt, $cartCookieJar);
                }
            } catch (\Throwable $exception) {
                Log::warning('[ZJMF 财务开通] 清理供应商购物车失败', [
                    'supplier_id' => $supplier->id,
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
            } finally {
                $latency['clear_cart_after_ms'] = $this->elapsedMilliseconds($clearStartedAt);
            }

            $logContext = array_merge($latency, [
                'result' => $result,
                'order_id' => (int) $order->id,
                'order_no' => (string) $order->order_no,
                'supplier_id' => (int) $supplier->id,
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
                'requested_host' => $requestedHost,
                'upstream_invoice_id' => $invoiceId,
                'upstream_host_id' => $hostId,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            if ($result === 'success') {
                Log::info('[ZJMF 财务开通] 上游开通请求耗时', $logContext);
            } else {
                $logContext['message'] = $errorMessage;
                $logContext['exception'] = $errorClass;
                Log::warning('[ZJMF 财务开通] 上游开通请求耗时', $logContext);
            }
        }
    }

    public function getProductProvisionConfig(Supplier $supplier, int $productId): array
    {
        return $this->transport->get($supplier, '/cart/get_product_config', $this->transport->login($supplier), [
            'pid' => $productId,
        ]);
    }

    private function buildCartPayload(Order $order, Product $product, int $currencyId): array
    {
        $configSnapshot = array_merge(
            (array) (($product->purchase_requires ?? [])['upstream_default_config'] ?? []),
            (array) ($order->config_snapshot ?? [])
        );
        $hostname = trim((string) ($configSnapshot['hostname'] ?? $order->domain ?? ''));
        $password = trim((string) ($configSnapshot['password'] ?? ''));

        if ($password === '') {
            $password = $this->generateProvisionPassword();
        }

        $configOption = $this->buildConfigOptionMap($product, $configSnapshot);

        $payload = [
            'pid' => $this->resolveProductUpstreamProductId($product),
            'billingcycle' => (string) $order->billing_cycle,
            'qty' => 1,
            'configoption' => $configOption,
            'customfield' => (array) ($configSnapshot['customfield'] ?? []),
            'currencyid' => $currencyId,
            'host' => $hostname,
            'password' => $password,
            'checkout' => 0,
        ];

        $osSelectionId = $this->resolveOsSelectionId($product, $configOption);
        if ($osSelectionId > 0) {
            $payload['os'] = ['id' => $osSelectionId];
        }

        return $payload;
    }

    private function resolveExistingHostId(Service $service): int
    {
        $bindingHostId = $this->bindingResolver()->upstreamServiceIdForService($service);
        $provisionData = (array) ($service->provision_data ?? []);

        return (int) (($bindingHostId ?? '') ?: ($provisionData['upstream_host_id'] ?? 0) ?: 0);
    }

    /**
     * Persist upstream references before any later network call can fail.
     *
     * The supplied service instance is updated as well, allowing the core
     * failure path to retain the checkpoint and a retry to verify the existing
     * resource instead of checking out another cart.
     */
    private function checkpointUpstreamProvision(
        ?Service $service,
        Supplier $supplier,
        Product $product,
        int $upstreamInvoiceId,
        array $upstreamHostIds,
        string $requestedHost,
    ): void {
        if (! $service instanceof Service || ! $service->exists) {
            return;
        }

        $freshService = $service->fresh() ?? $service;
        $provisionData = (array) ($freshService->provision_data ?? []);
        $hostIds = array_values(array_filter(array_map('intval', $upstreamHostIds), fn (int $hostId) => $hostId > 0));
        $hostId = (int) ($hostIds[0] ?? $provisionData['upstream_host_id'] ?? 0);

        $freshService->forceFill([
            'provision_data' => array_merge($provisionData, array_filter([
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'supplier_id' => (int) $supplier->id,
                'requested_host' => trim($requestedHost) !== '' ? trim($requestedHost) : null,
                'upstream_invoice_id' => $upstreamInvoiceId > 0 ? $upstreamInvoiceId : null,
                'upstream_host_id' => $hostId > 0 ? $hostId : null,
                'upstream_host_ids' => $hostIds !== [] ? $hostIds : null,
                'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
                'last_provision_attempt_at' => now()->format('Y-m-d H:i:s'),
            ], static fn (mixed $value): bool => $value !== null)),
        ])->save();

        $service->setAttribute('provision_data', $freshService->provision_data);
    }

    private function resolveProductUpstreamProductId(Product $product): int
    {
        $upstreamProductId = $this->bindingResolver()->upstreamProductIdForProduct($product);

        if ($upstreamProductId !== null) {
            return (int) $upstreamProductId;
        }

        throw new BusinessException('商品上游绑定不存在，无法自动开通');
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function buildConfigOptionMap(Product $product, array $configSnapshot): array
    {
        $result = [];

        foreach ((array) ($product->config_options ?? []) as $item) {
            $item = (array) $item;
            $optionId = $this->resolveOptionId($item);
            $field = $this->parseField($item);
            $type = (int) ($item['option_type'] ?? -1);

            if ($optionId <= 0 || $field === '') {
                continue;
            }

            if (in_array($type, self::RANGE_TYPES, true)) {
                $value = $configSnapshot[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $result[$optionId] = (int) $value;

                continue;
            }

            if ($field === 'os' && ! empty($configSnapshot['os_sub_id'])) {
                $result[$optionId] = (int) $configSnapshot['os_sub_id'];

                continue;
            }

            $selected = $configSnapshot[$field] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }

            $subId = $this->resolveSubId($item, $selected);
            if ($subId !== null) {
                $result[$optionId] = $subId;
            }
        }

        return $result;
    }

    private function resolveOptionId(array $item): int
    {
        foreach ([
            $item['id'] ?? null,
            $item['config_id'] ?? null,
            $item['extra']['id'] ?? null,
            $item['extra']['config_id'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        foreach ([(array) ($item['sub'] ?? []), (array) ($item['extra']['sub'] ?? [])] as $subList) {
            foreach ($subList as $sub) {
                $configId = $sub['config_id'] ?? $sub['configid'] ?? null;
                if (is_numeric($configId) && (int) $configId > 0) {
                    return (int) $configId;
                }
            }
        }

        return 0;
    }

    private function resolveSubId(array $item, mixed $selected): ?int
    {
        $selected = trim((string) $selected);
        if ($selected === '') {
            return null;
        }

        foreach ((array) ($item['sub'] ?? []) as $sub) {
            $subId = (int) ($sub['id'] ?? 0);
            $optionNameFirst = trim((string) ($sub['option_name_first'] ?? ''));
            $optionName = trim((string) ($sub['option_name'] ?? ''));

            if ($selected === (string) $subId || $selected === $optionNameFirst || $selected === $optionName) {
                return $subId > 0 ? $subId : null;
            }
        }

        return is_numeric($selected) ? (int) $selected : null;
    }

    private function parseField(array $item): string
    {
        $field = trim((string) ($item['field'] ?? ''));
        if ($field !== '') {
            return $field;
        }

        $type = (int) ($item['option_type'] ?? -1);
        if (isset(self::TYPE_FIELD_MAP[$type])) {
            return self::TYPE_FIELD_MAP[$type];
        }

        $source = (string) ($item['option_name'] ?? $item['spec_key'] ?? '');
        $parts = explode('|', $source);

        return trim((string) ($parts[0] ?? ''));
    }

    private function addProductToCart(Supplier $supplier, string $jwt, array $payload, array &$cookieJar): array
    {
        return $this->requestCartWithSession(
            $supplier,
            'POST',
            '/cart/add_to_shop',
            $payload,
            $jwt,
            $cookieJar,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );
    }

    private function clearCart(Supplier $supplier, string $jwt, array &$cookieJar): void
    {
        $response = $this->requestCartWithSession(
            $supplier,
            'POST',
            '/cart/clear',
            [],
            $jwt,
            $cookieJar,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ]
        );

        if ($this->hasSuccessfulUpstreamHttpStatus($response)
            && $this->extractResponseStatus($response) === 400
            && $this->isKnownEmptyCartResponse($response)) {
            return;
        }

        $this->assertUpstreamSuccess($response, [200], '清空上游购物车');
    }

    private function isKnownEmptyCartResponse(array $response): bool
    {
        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));

        return str_contains($message, '该订单已开通')
            && str_contains($message, '请勿重新开通');
    }

    private function requestCartWithSession(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload,
        string $jwt,
        array &$cookieJar,
        array $headers = [],
        array $query = []
    ): array {
        $meta = $this->transport->requestWithMeta(
            $supplier,
            $method,
            $uri,
            $payload,
            $jwt,
            $this->buildCartSessionHeaders($cookieJar, $headers),
            $query
        );

        $this->mergeResponseCookies($cookieJar, (array) ($meta['headers'] ?? []));

        return $this->responseWithHttpCode($meta);
    }

    private function buildCartSessionHeaders(array $cookieJar, array $headers = []): array
    {
        if ($cookieJar === []) {
            return $headers;
        }

        return array_merge($headers, ['Cookie: '.$this->formatCookieJar($cookieJar)]);
    }

    private function formatCookieJar(array $cookieJar): string
    {
        $pairs = [];

        foreach ($cookieJar as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);

            if ($name !== '' && $value !== '') {
                $pairs[] = "{$name}={$value}";
            }
        }

        return implode('; ', $pairs);
    }

    private function mergeResponseCookies(array &$cookieJar, array $responseHeaders): void
    {
        foreach ($responseHeaders as $header) {
            $line = trim((string) $header);
            if (! str_starts_with(strtolower($line), 'set-cookie:')) {
                continue;
            }

            $cookie = trim(substr($line, strlen('set-cookie:')));
            [$pair, $attributes] = array_pad(explode(';', $cookie, 2), 2, '');
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if ($value === '' || str_contains(strtolower($attributes), 'max-age=0')) {
                unset($cookieJar[$name]);

                continue;
            }

            $cookieJar[$name] = $value;
        }
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function extractInvoiceId(array $response, array $payload): int
    {
        $invoice = is_array($payload['invoice'] ?? null) ? $payload['invoice'] : [];

        return (int) (
            $payload['invoiceid']
            ?? $payload['invoice_id']
            ?? $invoice['id']
            ?? $response['invoiceid']
            ?? $response['invoice_id']
            ?? 0
        );
    }

    private function extractCartPosition(array $response, array $payload): int
    {
        foreach ([
            $payload['i'] ?? null,
            $payload['position'] ?? null,
            $response['i'] ?? null,
            $response['position'] ?? null,
            $response['data'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate >= 0) {
                return (int) $candidate;
            }
        }

        // The standard endpoint omits i on some Mofang Finance releases.
        // The cart is cleared under the supplier-scoped lock before adding one item.
        return 0;
    }

    private function extractHostIds(array $response, array $payload): array
    {
        $hostIds = $payload['hostid']
            ?? $payload['host_id']
            ?? $payload['hostids']
            ?? $payload['host_ids']
            ?? $response['hostid']
            ?? $response['host_id']
            ?? $response['hostids']
            ?? $response['host_ids']
            ?? [];

        if (! is_array($hostIds)) {
            $hostIds = [$hostIds];
        }

        return array_values(array_filter(array_map(
            fn ($value) => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            $hostIds
        )));
    }

    private function assertUpstreamSuccess(array $response, array $allowedStatuses, string $action): void
    {
        if (array_key_exists(self::UPSTREAM_HTTP_CODE_META_KEY, $response)
            && ! $this->hasSuccessfulUpstreamHttpStatus($response)) {
            $this->throwUpstreamFailure($response, $action);
        }

        $hasStatus = array_key_exists('status', $response)
            || array_key_exists('code', $response)
            || array_key_exists('status_code', $response);

        if (! $hasStatus) {
            return;
        }

        $status = $this->extractResponseStatus($response);
        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $this->throwUpstreamFailure($response, $action);
    }

    private function hasSuccessfulUpstreamHttpStatus(array $response): bool
    {
        $httpCode = (int) ($response[self::UPSTREAM_HTTP_CODE_META_KEY] ?? 0);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private function throwUpstreamFailure(array $response, string $action): never
    {
        $status = $this->extractResponseStatus($response);
        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[ZJMF 财务开通] 返回失败', [
            'action' => $action,
            'status' => $status,
            'http_code' => $response[self::UPSTREAM_HTTP_CODE_META_KEY] ?? null,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage(ProviderKey::ZJMF_FINANCE_API, $action, $message));
    }

    private function extractResponseStatus(array $response): int
    {
        return (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);
    }

    private function findHostIdsForProvision(Supplier $supplier, string $jwt, int $invoiceId, string $hostname): array
    {
        $response = $this->responseWithHttpCode($this->transport->requestWithMeta(
            $supplier,
            'GET',
            '/host/list',
            [],
            $jwt,
            [],
            [
                'show_type' => 'list',
                'orderby' => 'id',
                'sort' => 'DESC',
            ]
        ));
        $this->assertUpstreamSuccess($response, [200], '查询上游已开通产品');

        $payload = $this->extractPayload($response);
        $hosts = is_array($payload['list'] ?? null) ? $payload['list'] : [];

        return array_values(array_unique(array_filter(array_map(function ($host) use ($invoiceId, $hostname) {
            if (! is_array($host)) {
                return null;
            }

            $domain = trim((string) ($host['domain'] ?? ''));
            $hostInvoiceId = (int) ($host['invoice_id'] ?? $host['invoiceid'] ?? 0);
            $matchesInvoice = $invoiceId > 0 && $hostInvoiceId === $invoiceId;
            $matchesHostname = $hostname !== '' && $domain === $hostname;

            if (! $matchesInvoice && ! $matchesHostname) {
                return null;
            }

            $id = (int) ($host['id'] ?? 0);

            return $id > 0 ? $id : null;
        }, $hosts))));
    }

    /**
     * 保留底层 HTTP 状态，避免上游 4xx/5xx JSON 缺少业务状态码时被误判为成功。
     */
    private function responseWithHttpCode(array $meta): array
    {
        $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];
        $response[self::UPSTREAM_HTTP_CODE_META_KEY] = (int) ($meta['http_code'] ?? 0);

        return $response;
    }

    private function assertHostDetailPresent(array $hostDetail, int $hostId): void
    {
        if ($hostDetail !== []) {
            return;
        }

        Log::warning('[ZJMF 财务开通] 上游实例详情为空，已停止标记开通成功', [
            'upstream_host_id' => $hostId,
        ]);

        throw new BusinessException('上游未返回实例详情，无法确认开通结果，请稍后重试或先核实上游实例');
    }

    private function resolveCurrencyId(Order $order, Product $product, array $upstreamProductConfig, int $upstreamProductId): int
    {
        $configSnapshot = (array) ($order->config_snapshot ?? []);
        $candidates = [
            $configSnapshot['currencyid'] ?? null,
            $configSnapshot['currency_id'] ?? null,
            $product->getAttribute('currency_id'),
        ];

        foreach ((array) ($upstreamProductConfig['product_pricings'] ?? []) as $pricing) {
            if (! is_array($pricing)) {
                continue;
            }

            $pricedProductId = (int) ($pricing['relid'] ?? 0);
            if ($pricedProductId > 0 && $pricedProductId !== $upstreamProductId) {
                continue;
            }

            $candidates[] = $pricing['currency'] ?? $pricing['currencyid'] ?? $pricing['currency_id'] ?? null;
        }

        foreach ((array) ($product->config_options ?? []) as $option) {
            foreach ((array) ((array) $option)['sub'] as $subOption) {
                foreach ((array) (((array) $subOption)['pricings'] ?? []) as $pricing) {
                    if (is_array($pricing)) {
                        $candidates[] = $pricing['currency'] ?? $pricing['currencyid'] ?? $pricing['currency_id'] ?? null;
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        throw new BusinessException('无法确定上游商品币种，已停止创建上游订单');
    }

    private function resolveOsSelectionId(Product $product, array $configOption): int
    {
        foreach ((array) ($product->config_options ?? []) as $item) {
            $item = (array) $item;
            if ($this->parseField($item) !== 'os') {
                continue;
            }

            $optionId = $this->resolveOptionId($item);
            $selectionId = $configOption[$optionId] ?? null;
            if (is_numeric($selectionId) && (int) $selectionId > 0) {
                return (int) $selectionId;
            }
        }

        return 0;
    }

    private function generateProvisionPassword(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 13; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return substr($password.'Aa1', 0, 16);
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
