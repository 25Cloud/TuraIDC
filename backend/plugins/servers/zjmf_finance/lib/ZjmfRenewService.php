<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\Upstream\ProviderKey;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

final class ZjmfRenewService
{
    private const RENEWAL_DURATION_BY_BILLING_CYCLE = [
        'monthly' => 1,
        'quarterly' => 3,
        'semiannually' => 6,
        'annually' => 12,
    ];

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
    ) {}

    public function renewHost(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        return $this->submitRenewal($supplier, $hostId, $billingCycle, $this->transport->login($supplier));
    }

    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $jwt = $this->transport->login($supplier);
        $renewResponse = $this->submitRenewal($supplier, $hostId, $billingCycle, $jwt);
        $this->assertUpstreamSuccess($renewResponse, [200], '提交上游续费');

        $renewPayload = $this->extractPayload($renewResponse);
        $upstreamInvoiceId = $this->extractInvoiceId($renewResponse, $renewPayload);
        throw_if($upstreamInvoiceId <= 0, new BusinessException('上游未返回续费账单 ID'));

        $payment = $this->extractPaymentMethod($renewResponse, $renewPayload);
        $recoveryContext = $this->buildRecoveryContext($payment);

        try {
            $paymentResponse = $this->payAndCheckInvoice($supplier, $upstreamInvoiceId, $payment, $jwt);
        } catch (\Throwable $exception) {
            return $this->pendingRenewalInvoice(
                $upstreamInvoiceId,
                $renewResponse,
                [],
                $recoveryContext,
                $exception instanceof BusinessException
                    ? $exception->getMessage()
                    : '使用供应商余额支付续费账单失败',
            );
        }

        $paymentStatus = $this->extractPaymentStatus($paymentResponse);
        $paymentCompleted = $this->isPaymentCompleted($paymentResponse);

        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'upstream_amount' => $this->extractUpstreamAmount($renewResponse, $renewPayload),
            'renew_response' => $renewResponse,
            'fund_response' => $paymentResponse,
            'fund_status' => $paymentStatus,
            'payment_completed' => $paymentCompleted,
            'fund_error' => $paymentCompleted ? '' : '上游续费账单仍未支付完成，请检查供应商余额',
            'recovery_context' => $recoveryContext,
            'host_detail' => $paymentCompleted
                ? $this->readHostDetailSafely($supplier, $hostId, $jwt, $upstreamInvoiceId)
                : [],
        ];
    }

    /**
     * 尽力提取上游续费实扣金额用于对账：上游字段名不统一，尝试常见金额键，
     * 取到数值则返回两位小数字符串，取不到返回空串（不阻断续费流程）。
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $payload
     */
    private function extractUpstreamAmount(array $response, array $payload): string
    {
        foreach ([$payload, $response, $this->extractPayload($response)] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach (['total', 'total_fee', 'amount', 'money', 'subtotal', 'order_amount'] as $key) {
                if (isset($candidate[$key]) && is_numeric($candidate[$key])) {
                    return number_format((float) $candidate[$key], 2, '.', '');
                }
            }
        }

        return '';
    }

    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array
    {
        return $this->recoverRenewInvoiceWithContext($supplier, $hostId, $upstreamInvoiceId);
    }

    public function recoverRenewInvoiceWithContext(
        Supplier $supplier,
        int $hostId,
        int $upstreamInvoiceId,
        array $recoveryContext = [],
    ): ?array {
        if ($upstreamInvoiceId <= 0) {
            return null;
        }

        $jwt = $this->transport->login($supplier);
        $paymentResponse = $this->checkInvoicePayment($supplier, $upstreamInvoiceId, $jwt);

        if (! $this->isPaymentCompleted($paymentResponse)) {
            $payment = trim((string) ($recoveryContext['payment'] ?? ''));
            if ($payment === '') {
                return $this->recoveredPendingInvoice($upstreamInvoiceId, $paymentResponse, '上游续费账单仍未支付完成，请检查供应商余额');
            }

            try {
                $paymentResponse = $this->payAndCheckInvoice($supplier, $upstreamInvoiceId, $payment, $jwt);
            } catch (\Throwable $exception) {
                return $this->recoveredPendingInvoice(
                    $upstreamInvoiceId,
                    [],
                    $exception instanceof BusinessException
                        ? $exception->getMessage()
                        : '恢复供应商余额支付续费账单失败',
                );
            }
        }

        $paymentCompleted = $this->isPaymentCompleted($paymentResponse);

        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'fund_response' => $paymentResponse,
            'fund_status' => $this->extractPaymentStatus($paymentResponse),
            'host_detail' => $paymentCompleted
                ? $this->readHostDetailSafely($supplier, $hostId, $jwt, $upstreamInvoiceId)
                : [],
            'recovered' => true,
            'funded' => $paymentCompleted,
            'payment_completed' => $paymentCompleted,
            'fund_error' => $paymentCompleted ? '' : '上游续费账单仍未支付完成，请检查供应商余额',
        ];
    }

    private function submitRenewal(Supplier $supplier, int $hostId, string $billingCycle, string $jwt): array
    {
        return $this->transport->post($supplier, '/host/renew', $this->buildRenewPayload($hostId, $billingCycle), $jwt);
    }

    private function buildRenewPayload(int $hostId, string $billingCycle): array
    {
        $billingCycle = strtolower(trim($billingCycle));
        $duration = self::RENEWAL_DURATION_BY_BILLING_CYCLE[$billingCycle] ?? null;
        throw_if($duration === null, new BusinessException('不支持的续费周期'));

        return [
            'hostid' => $hostId,
            'billingcycles' => $billingCycle,
            'duration' => $duration,
        ];
    }

    private function payAndCheckInvoice(Supplier $supplier, int $upstreamInvoiceId, string $payment, string $jwt): array
    {
        $this->payInvoiceWithBalance($supplier, $upstreamInvoiceId, $payment, $jwt);

        return $this->checkInvoicePayment($supplier, $upstreamInvoiceId, $jwt);
    }

    private function payInvoiceWithBalance(Supplier $supplier, int $upstreamInvoiceId, string $payment, string $jwt): void
    {
        throw_if(trim($payment) === '', new BusinessException('上游未返回续费付款方式'));

        $this->transport->requestText($supplier, 'POST', '/pay', [
            'invoiceid' => $upstreamInvoiceId,
            'use_credit' => 1,
            'payment' => $payment,
            'use_credit_limit' => 0,
        ], $jwt, [], [
            'action' => 'billing',
            'pay' => 'true',
        ]);
    }

    private function checkInvoicePayment(Supplier $supplier, int $upstreamInvoiceId, string $jwt): array
    {
        $response = $this->transport->post($supplier, '/check_order', ['id' => $upstreamInvoiceId], $jwt);
        $status = $this->extractPaymentStatus($response);

        if (! in_array($status, [0, 200, 1000, 1001], true)) {
            $this->assertUpstreamSuccess($response, [200, 1000, 1001], '确认上游续费账单支付状态');
        }

        return $response;
    }

    private function pendingRenewalInvoice(
        int $upstreamInvoiceId,
        array $renewResponse,
        array $paymentResponse,
        array $recoveryContext,
        string $error,
    ): array {
        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'renew_response' => $renewResponse,
            'fund_response' => $paymentResponse,
            'fund_status' => $this->extractPaymentStatus($paymentResponse),
            'payment_completed' => false,
            'fund_error' => $error,
            'recovery_context' => $recoveryContext,
            'host_detail' => [],
        ];
    }

    private function recoveredPendingInvoice(int $upstreamInvoiceId, array $paymentResponse, string $error): array
    {
        return [
            'upstream_invoice_id' => $upstreamInvoiceId,
            'fund_response' => $paymentResponse,
            'fund_status' => $this->extractPaymentStatus($paymentResponse),
            'host_detail' => [],
            'recovered' => true,
            'funded' => false,
            'payment_completed' => false,
            'fund_error' => $error,
        ];
    }

    private function readHostDetailSafely(Supplier $supplier, int $hostId, string $jwt, int $upstreamInvoiceId): array
    {
        try {
            $detailResponse = $this->transport->getHostDetail($supplier, $hostId, $jwt);
            $this->assertUpstreamSuccess($detailResponse, [200], '读取上游续费结果');
            $detailPayload = $this->extractPayload($detailResponse);

            return is_array($detailPayload['host'] ?? null) ? $detailPayload['host'] : [];
        } catch (\Throwable $exception) {
            Log::warning('[ZJMF 财务续费] 上游续费已提交，读取实例详情失败', [
                'supplier_id' => (int) $supplier->id,
                'host_id' => $hostId,
                'upstream_invoice_id' => $upstreamInvoiceId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return [];
        }
    }

    private function extractPayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function extractInvoiceId(array $response, array $payload): int
    {
        return (int) ($payload['invoiceid'] ?? $response['invoiceid'] ?? 0);
    }

    private function extractPaymentMethod(array $response, array $payload): string
    {
        return trim((string) ($payload['payment'] ?? $response['payment'] ?? ''));
    }

    private function buildRecoveryContext(string $payment): array
    {
        return $payment === '' ? [] : ['payment' => $payment];
    }

    private function extractPaymentStatus(array $response): int
    {
        $statuses = [];
        foreach ([$response, $this->extractPayload($response)] as $candidate) {
            foreach (['status', 'code', 'status_code'] as $key) {
                if (isset($candidate[$key]) && is_numeric($candidate[$key])) {
                    $statuses[] = (int) $candidate[$key];
                }
            }
        }

        foreach ($statuses as $status) {
            if (in_array($status, [1000, 1001], true)) {
                return $status;
            }
        }

        return $statuses[0] ?? 0;
    }

    private function isPaymentCompleted(array $response): bool
    {
        return in_array($this->extractPaymentStatus($response), [1000, 1001], true);
    }

    private function assertUpstreamSuccess(array $response, array $allowedStatuses, string $action): void
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 200);
        if (in_array($status, $allowedStatuses, true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[ZJMF 财务续费] 返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage(ProviderKey::ZJMF_FINANCE_API, $action, $message));
    }
}
