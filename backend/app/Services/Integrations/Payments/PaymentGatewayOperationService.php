<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Models\Payment;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Plugins\PaymentGatewayBindingResolver;
use Illuminate\Support\Facades\Schema;

class PaymentGatewayOperationService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentGatewayBindingResolver $bindings,
    ) {}

    public function gateway(string $gateway): PaymentGatewayInterface
    {
        return $this->gateways->gateway($gateway);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function paymentCreatePayload(array $payload): array
    {
        $gateway = (string) ($payload['gateway_key'] ?? $payload['gateway'] ?? '');
        unset($payload['gateway']);

        return array_merge($payload, $this->auditColumnsForGateway($gateway));
    }

    public function ensurePaymentAudit(Payment $payment, string $gateway, ?string $traceId = null): void
    {
        $payload = [];
        $context = $this->bindings->contextForPayment($payment);

        if ($context['plugin_id'] !== null && (int) ($payment->plugin_id ?? 0) <= 0 && Schema::hasColumn('payments', 'plugin_id')) {
            $payload['plugin_id'] = $context['plugin_id'];
        }

        $gatewayKey = $context['gateway_key'] ?: $this->bindings->normalizeGatewayKey($gateway);
        if ($gatewayKey !== '' && trim((string) ($payment->gateway_key ?? '')) === '' && Schema::hasColumn('payments', 'gateway_key')) {
            $payload['gateway_key'] = $gatewayKey;
        }

        $resolvedTraceId = trim((string) ($traceId ?? ''));
        if ($resolvedTraceId !== '' && trim((string) ($payment->trace_id ?? '')) === '') {
            $payload['trace_id'] = $resolvedTraceId;
        }

        if ($payload !== []) {
            $payment->forceFill($payload)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function precreate(
        string $gateway,
        string $outTradeNo,
        float $amount,
        string $subject,
        ?string $timeoutExpress = null,
        array $context = [],
    ): array {
        return $this->gateway($gateway)
            ->precreate(new PaymentPrecreateRequest(
                outTradeNo: $outTradeNo,
                amount: $amount,
                subject: $subject,
                timeoutExpress: $timeoutExpress,
                context: $context,
            ))
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $gateway, string $outTradeNo): array
    {
        return $this->gateway($gateway)->query($outTradeNo)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(
        string $gateway,
        string $outTradeNo,
        float $refundAmount,
        string $refundReason,
        ?string $tradeNo,
        string $outRequestNo,
    ): array {
        return $this->gateway($gateway)
            ->refund(new PaymentRefundRequest(
                outTradeNo: $outTradeNo,
                refundAmount: $refundAmount,
                refundReason: $refundReason,
                tradeNo: $tradeNo,
                outRequestNo: $outRequestNo,
            ))
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditColumnsForGateway(string $gateway): array
    {
        $context = $this->bindings->contextForGateway($gateway);
        $columns = [];

        if (Schema::hasColumn('payments', 'plugin_id')) {
            $columns['plugin_id'] = $context['plugin_id'];
        }

        if (Schema::hasColumn('payments', 'gateway_key')) {
            $columns['gateway_key'] = $context['gateway_key'];
        }

        return $columns;
    }
}
