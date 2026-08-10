<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\PaymentGatewayCode;
use App\Exceptions\BusinessException;
use App\Models\Payment;
use App\Models\User;
use App\Services\Integrations\Payments\PaymentGatewayManager;

class ClientRechargeWorkflowService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CheckoutSecurityService $checkoutSecurity,
        private readonly PaymentGatewayManager $paymentGateways,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function gatewayOptions(): array
    {
        return $this->paymentGateways->availableThirdPartyGatewayOptions();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload, string $ipAddress): array
    {
        $options = $this->gatewayOptions();
        $gateway = trim((string) ($payload['gateway'] ?? ''));
        $paymentType = trim((string) ($payload['payment_type'] ?? ''));
        if ($gateway === '') {
            $firstOption = $options[0] ?? [];
            $gateway = (string) ($firstOption['key'] ?? '');
            $paymentType = (string) ($firstOption['payment_type'] ?? '');
        }

        $selectedOption = $this->gatewayOption($options, $gateway, $paymentType);
        if ($selectedOption === null) {
            throw new BusinessException('当前没有可用支付方式，请联系管理员开启支付渠道');
        }

        $paymentType = (string) ($selectedOption['payment_type'] ?? '');
        $gatewayContext = $paymentType !== '' ? ['payment_type' => $paymentType] : [];
        $result = $this->payments->rechargeByGateway(
            $user,
            (float) $payload['amount'],
            $gateway,
            $gatewayContext,
        );

        $payment = Payment::query()
            ->where('payment_no', (string) ($result['payment_no'] ?? ''))
            ->where('user_id', $user->id)
            ->whereGatewayKey($gateway)
            ->whereNull('invoice_id')
            ->first();
        if (! $payment) {
            throw new BusinessException('充值支付记录不存在', 40400, 404);
        }

        $gatewayPayload = [
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => (string) ($selectedOption['name'] ?? PaymentGatewayCode::label($gateway)),
        ];
        if ($paymentType !== '') {
            $gatewayPayload['payment_type'] = $paymentType;
            $gatewayPayload['payment_type_label'] = (string) ($selectedOption['label'] ?? '');
        }

        return array_merge(
            $gatewayPayload,
            $result,
            $this->checkoutSecurity->issueRechargePollToken($payment, (int) $user->id, $ipAddress),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user, string $paymentNo, string $pollToken, string $ipAddress): array
    {
        $payment = Payment::where('payment_no', $paymentNo)
            ->where('user_id', $user->id)
            ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
            ->firstOrFail();

        $payment = $this->payments->cancelExpiredPendingRecharge($payment, [
            'actor_type' => 'system',
            'actor_name' => 'recharge-status-poll',
            'reason' => 'payment_window_expired',
        ]);
        $this->checkoutSecurity->assertRechargePollToken($pollToken, $payment, (int) $user->id, $ipAddress);

        $result = $this->payments->queryRechargeStatus($payment);
        if ($result['paid']) {
            $user->refresh();
            $result['balance'] = number_format((float) $user->balance, 2, '.', '');
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function gatewayOption(array $options, string $gateway, string $paymentType): ?array
    {
        foreach ($options as $option) {
            if ((string) ($option['key'] ?? '') !== $gateway) {
                continue;
            }

            if ($paymentType !== '' && $paymentType !== trim((string) ($option['payment_type'] ?? ''))) {
                continue;
            }

            return $option;
        }

        return null;
    }
}
