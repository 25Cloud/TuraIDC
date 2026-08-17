<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\Upstream\ProviderKey;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

final class ZjmfNetworkService
{
    private const UPSTREAM_HTTP_CODE_META_KEY = '_zjmf_upstream_http_code';

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
        private readonly ZjmfConsoleService $consoleService,
    ) {}

    public function getHostUpgradeConfigOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $response = $this->transport->get($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig", $this->resolveJwt($supplier, $jwt));

        return [
            'response' => $response,
            'options' => $this->normalizeUpgradeConfigOptions($response),
        ];
    }

    public function previewHostConfigUpgrade(Supplier $supplier, int $hostId, array $configOption, ?string $jwt = null): array
    {
        return $this->transport->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig", [
            'configoption' => $configOption,
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function checkoutHostConfigUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/checkout", [], $this->resolveJwt($supplier, $jwt));
    }

    public function getHostUpgradePromoPreview(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->transport->put($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [
            'promo_code' => trim($promoCode),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function removeHostUpgradePromoCode(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->delete($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [], $this->resolveJwt($supplier, $jwt));
    }

    public function getHostUpgradeOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->get($supplier, "/v1/hosts/{$hostId}/actions/upgrade", $this->resolveJwt($supplier, $jwt));
    }

    public function previewHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
    {
        return $this->transport->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade", [
            'product_id' => $productId,
            'billingcycle' => trim($billingCycle),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function applyHostUpgradePromoCode(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        return $this->transport->put($supplier, "/v1/hosts/{$hostId}/actions/upgrade/promo", [
            'promo_code' => trim($promoCode),
        ], $this->resolveJwt($supplier, $jwt));
    }

    public function checkoutHostUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        return $this->transport->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade/checkout", [], $this->resolveJwt($supplier, $jwt));
    }

    public function buyFlowPacket(Supplier $supplier, string $rootUrl, int $flowPacketId, int $hostId, ?string $jwt = null): array
    {
        $rootUrl = rtrim($rootUrl, '/');
        $payload = [
            'flow_packet_id' => $flowPacketId,
            'service_id' => $hostId,
            'id' => $hostId,
        ];
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);

        $pageResponse = $this->transport->post(
            $supplier,
            $rootUrl.'/servicedetail?'.http_build_query(['id' => $hostId, 'action' => 'flowpacket']),
            $payload,
            $resolvedJwt
        );
        if ((int) ($pageResponse['code'] ?? -1) === 0) {
            return $pageResponse;
        }

        $legacyResponse = $this->transport->post($supplier, $rootUrl.'/dcim/buy_flow_packet', $payload, $resolvedJwt);
        if ((int) ($legacyResponse['code'] ?? -1) === 0) {
            return $legacyResponse;
        }

        return $pageResponse;
    }

    public function fundInvoice(Supplier $supplier, int $invoiceId, ?string $jwt = null, string $action = '支付上游账单'): array
    {
        $response = $this->transport->post($supplier, "/v1/invoices/{$invoiceId}/fund", [], $this->resolveJwt($supplier, $jwt));
        $this->assertUpstreamSuccess($response, [200, 1001], $action);

        return [
            ...$response,
            'fund_status' => $this->responseStatus($response),
            'payment_completed' => $this->responseStatus($response) === 1001,
        ];
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
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);
        $invoiceId = 0;

        if ($mode === 'flowpacket') {
            $buyResponse = $this->buyFlowPacket($supplier, $rootUrl, $flowPacketId, $hostId, $resolvedJwt);
            $buyCode = (int) ($buyResponse['code'] ?? -1);
            throw_if($buyCode !== 0, new BusinessException('上游流量包购买失败：'.(string) ($buyResponse['msg'] ?? $buyResponse['message'] ?? '未知错误')));
            $invoiceId = $this->extractInvoiceId($buyResponse, $this->extractPayload($buyResponse));
        } else {
            $previewResponse = $this->previewHostConfigUpgrade($supplier, $hostId, $configOption, $resolvedJwt);
            $this->assertUpstreamSuccess($previewResponse, [200, 1001], '提交流量包升级配置');
            $checkoutResponse = $this->checkoutHostConfigUpgrade($supplier, $hostId, $resolvedJwt);
            $this->assertUpstreamSuccess($checkoutResponse, [200, 1001], '生成流量包账单');
            $invoiceId = $this->extractInvoiceId($checkoutResponse, $this->extractPayload($checkoutResponse));
            throw_if($invoiceId <= 0, new BusinessException('上游未返回流量包账单 ID'));
            $fundResponse = $this->fundInvoice($supplier, $invoiceId, $resolvedJwt, '使用供应商余额支付流量包账单');
            $this->assertFundPaymentCompleted($fundResponse, '流量包账单');
        }

        return [
            'upstream_invoice_id' => $invoiceId,
            'host_detail' => $this->readConfirmedHostDetail($supplier, $hostId, $resolvedJwt, '读取流量包购买结果'),
        ];
    }

    public function purchaseHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, string $promoCode = '', ?string $jwt = null): array
    {
        $resolvedJwt = $this->resolveJwt($supplier, $jwt);
        $previewResponse = $this->previewHostUpgrade($supplier, $hostId, $productId, $billingCycle, $resolvedJwt);
        $this->assertUpstreamSuccess($previewResponse, [200, 1001], '提交产品升降级预览');

        if (trim($promoCode) !== '') {
            $promoResponse = $this->applyHostUpgradePromoCode($supplier, $hostId, $promoCode, $resolvedJwt);
            $this->assertUpstreamSuccess($promoResponse, [200, 1001], '应用产品升降级优惠码');
        }

        $checkoutResponse = $this->checkoutHostUpgrade($supplier, $hostId, $resolvedJwt);
        $this->assertUpstreamSuccess($checkoutResponse, [200, 1001], '生成产品升降级账单');
        $invoiceId = $this->extractInvoiceId($checkoutResponse, $this->extractPayload($checkoutResponse));
        throw_if($invoiceId <= 0, new BusinessException('上游未返回产品升降级账单 ID', 42200));
        $fundResponse = $this->fundInvoice($supplier, $invoiceId, $resolvedJwt, '使用供应商余额支付产品升降级账单');
        $this->assertFundPaymentCompleted($fundResponse, '产品升降级账单');

        return [
            'upstream_invoice_id' => $invoiceId,
            'host_detail' => $this->readConfirmedHostDetail($supplier, $hostId, $resolvedJwt, '读取产品升降级结果'),
        ];
    }

    private function normalizeUpgradeConfigOptions(array $response): array
    {
        $payload = $this->extractPayload($response);
        $options = $payload['configoption'] ?? $payload['options'] ?? $payload;

        return is_array($options) ? array_values(array_filter($options, 'is_array')) : [];
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function extractHostDetail(array $response): array
    {
        $payload = $this->extractPayload($response);

        return is_array($payload['host'] ?? null) ? $payload['host'] : [];
    }

    private function extractInvoiceId(array $response, array $payload): int
    {
        return (int) ($payload['invoiceid'] ?? $response['invoiceid'] ?? 0);
    }

    private function readConfirmedHostDetail(Supplier $supplier, int $hostId, string $jwt, string $action): array
    {
        $detailResponse = $this->responseWithHttpCode(
            $this->consoleService->getHostDetailWithMeta($supplier, $hostId, $jwt)
        );
        $this->assertUpstreamSuccess($detailResponse, [200], $action);
        $hostDetail = $this->extractHostDetail($detailResponse);
        if ($hostDetail === []) {
            throw new BusinessException('上游未返回实例详情，无法确认操作结果，请稍后重试或先核实上游实例', 42200);
        }

        return $hostDetail;
    }

    private function assertFundPaymentCompleted(array $response, string $invoiceLabel): void
    {
        if (($response['payment_completed'] ?? false) === true) {
            return;
        }

        throw new BusinessException($invoiceLabel.'已生成，但供应商余额付款仍在处理中', 42200);
    }

    private function responseStatus(array $response): int
    {
        return (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);
    }

    private function assertUpstreamSuccess(array $response, array $allowedStatuses, string $action): void
    {
        if (array_key_exists(self::UPSTREAM_HTTP_CODE_META_KEY, $response)
            && ! $this->hasSuccessfulUpstreamHttpStatus($response)) {
            $this->throwUpstreamFailure($response, $action);
        }

        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 200);
        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $this->throwUpstreamFailure($response, $action);
    }

    private function responseWithHttpCode(array $meta): array
    {
        $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];
        $response[self::UPSTREAM_HTTP_CODE_META_KEY] = (int) ($meta['http_code'] ?? 0);

        return $response;
    }

    private function hasSuccessfulUpstreamHttpStatus(array $response): bool
    {
        $httpCode = (int) ($response[self::UPSTREAM_HTTP_CODE_META_KEY] ?? 0);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private function throwUpstreamFailure(array $response, string $action): never
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);
        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[ZJMF 财务控制台] 返回失败', [
            'action' => $action,
            'status' => $status,
            'http_code' => $response[self::UPSTREAM_HTTP_CODE_META_KEY] ?? null,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage(ProviderKey::ZJMF_FINANCE_API, $action, $message), 42200);
    }

    private function resolveJwt(Supplier $supplier, ?string $jwt): string
    {
        $jwt = trim((string) $jwt);

        return $jwt !== '' ? $jwt : $this->transport->login($supplier);
    }
}
