<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\GatewayLog;
use App\Models\Payment;
use App\Services\Integrations\Plugins\PaymentGatewayBindingResolver;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Schema;

class GatewayLogService
{
    public function __construct(
        private readonly PaymentGatewayBindingResolver $paymentGatewayBindingResolver,
    ) {}

    public function record(
        string $gateway,
        string $action,
        ?string $outTradeNo = null,
        ?string $tradeNo = null,
        ?int $invoiceId = null,
        array $requestData = [],
        array $responseData = [],
        string $resultStatus = 'unknown',
        ?string $errorMsg = null,
        ?string $ipAddress = null,
        ?string $traceId = null,
    ): GatewayLog {
        $payment = $this->resolvePayment($outTradeNo, $tradeNo, $invoiceId);
        $context = $payment instanceof Payment
            ? $this->paymentGatewayBindingResolver->contextForPayment($payment)
            : $this->paymentGatewayBindingResolver->contextForGateway($gateway);
        $resolvedTraceId = trim((string) ($traceId ?? $payment?->trace_id ?? ''));

        $payload = [
            'gateway' => $gateway,
            'action' => $action,
            'out_trade_no' => $outTradeNo,
            'trade_no' => $tradeNo,
            'invoice_id' => $invoiceId,
            'request_data' => SensitiveDataSanitizer::sanitize($requestData),
            'response_data' => SensitiveDataSanitizer::sanitize($responseData),
            'result_status' => $resultStatus,
            'error_msg' => $errorMsg,
            'ip_address' => $ipAddress,
        ];

        if (Schema::hasColumn('gateway_logs', 'plugin_id')) {
            $payload['plugin_id'] = $context['plugin_id'];
        }

        if (Schema::hasColumn('gateway_logs', 'gateway_key')) {
            $payload['gateway_key'] = $context['gateway_key'];
        }

        if (Schema::hasColumn('gateway_logs', 'trace_id')) {
            $payload['trace_id'] = $resolvedTraceId !== '' ? $resolvedTraceId : null;
        }

        return GatewayLog::query()->create($payload);
    }

    public function recordSuccess(
        string $gateway,
        string $action,
        ?string $outTradeNo = null,
        ?string $tradeNo = null,
        ?int $invoiceId = null,
        array $requestData = [],
        array $responseData = [],
        ?string $ipAddress = null,
        ?string $traceId = null,
    ): GatewayLog {
        return $this->record(
            gateway: $gateway,
            action: $action,
            outTradeNo: $outTradeNo,
            tradeNo: $tradeNo,
            invoiceId: $invoiceId,
            requestData: $requestData,
            responseData: $responseData,
            resultStatus: 'success',
            ipAddress: $ipAddress,
            traceId: $traceId,
        );
    }

    public function recordFailure(
        string $gateway,
        string $action,
        string $errorMsg,
        ?string $outTradeNo = null,
        ?int $invoiceId = null,
        array $requestData = [],
        array $responseData = [],
        ?string $ipAddress = null,
        ?string $traceId = null,
    ): GatewayLog {
        return $this->record(
            gateway: $gateway,
            action: $action,
            outTradeNo: $outTradeNo,
            invoiceId: $invoiceId,
            requestData: $requestData,
            responseData: $responseData,
            resultStatus: 'failed',
            errorMsg: $errorMsg,
            ipAddress: $ipAddress,
            traceId: $traceId,
        );
    }

    private function resolvePayment(?string $outTradeNo, ?string $tradeNo, ?int $invoiceId): ?Payment
    {
        if (! Schema::hasTable('payments')) {
            return null;
        }

        $resolvedOutTradeNo = trim((string) $outTradeNo);
        $resolvedTradeNo = trim((string) $tradeNo);
        $resolvedInvoiceId = (int) ($invoiceId ?? 0);

        if ($resolvedOutTradeNo === '' && $resolvedTradeNo === '' && $resolvedInvoiceId <= 0) {
            return null;
        }

        return Payment::query()
            ->where(static function ($query) use ($resolvedOutTradeNo, $resolvedTradeNo, $resolvedInvoiceId): void {
                if ($resolvedOutTradeNo !== '') {
                    $query->orWhere('payment_no', $resolvedOutTradeNo);
                }

                if ($resolvedTradeNo !== '') {
                    $query->orWhere('trade_no', $resolvedTradeNo);
                }

                if ($resolvedInvoiceId > 0) {
                    $query->orWhere('invoice_id', $resolvedInvoiceId);
                }
            })
            ->latest('id')
            ->first();
    }
}
