<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments;

use App\Constants\PaymentGatewayCode;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;

final readonly class PaymentGatewayManager
{
    public function __construct(
        private PaymentGatewayRegistry $registry,
    ) {}

    public function gateway(?string $key = null): PaymentGatewayInterface
    {
        $selectedKey = trim((string) ($key ?: config('integrations.payments.default', PaymentGatewayCode::ALIPAY)));

        return $this->registry->get($selectedKey);
    }

    public function alipay(): PaymentGatewayInterface
    {
        return $this->gateway(PaymentGatewayCode::ALIPAY);
    }

    /**
     * @return array<int, PaymentGatewayInterface>
     */
    public function availableThirdPartyGateways(): array
    {
        $available = [];

        foreach ($this->registry->all() as $gateway) {
            $key = PaymentGatewayCode::normalize($gateway->key());
            if (! PaymentGatewayCode::isThirdParty($key)) {
                continue;
            }

            try {
                if (! $gateway->isEnabled()) {
                    continue;
                }
            } catch (\Throwable $exception) {
                Log::warning('[payment] skip unavailable gateway while listing client payment methods', [
                    'gateway' => $key,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $available[] = $gateway;
        }

        return $available;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableThirdPartyGatewayOptions(): array
    {
        return collect($this->availableThirdPartyGateways())
            ->flatMap(fn (PaymentGatewayInterface $gateway): array => $this->gatewayPaymentOptions($gateway))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gatewayPaymentOptions(PaymentGatewayInterface $gateway): array
    {
        $key = PaymentGatewayCode::normalize($gateway->key());
        $options = [];

        if (method_exists($gateway, 'paymentOptions')) {
            $options = collect($gateway->paymentOptions())
                ->map(fn (array $option): ?array => $this->normalizeGatewayOption($option, $key, $gateway->name()))
                ->filter()
                ->values()
                ->all();
        }

        return $options !== [] ? $options : [$this->defaultGatewayOption($key, $gateway->name())];
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>|null
     */
    private function normalizeGatewayOption(array $option, string $gatewayKey, string $gatewayName): ?array
    {
        $key = PaymentGatewayCode::normalize((string) ($option['key'] ?? $gatewayKey));
        if ($key === '' || ! PaymentGatewayCode::isThirdParty($key)) {
            return null;
        }

        $name = trim((string) ($option['name'] ?? ''));
        $label = trim((string) ($option['label'] ?? ''));
        $paymentType = trim((string) ($option['payment_type'] ?? ''));
        $optionKey = trim((string) ($option['option_key'] ?? ''));

        if ($optionKey === '') {
            $optionKey = $paymentType !== '' ? "{$key}:{$paymentType}" : $key;
        }

        $payload = [
            'key' => $key,
            'name' => $name !== '' ? $name : ($label !== '' ? $label : $gatewayName),
            'label' => $label !== '' ? $label : ($name !== '' ? $name : PaymentGatewayCode::label($key)),
            'option_key' => $optionKey,
        ];

        if ($paymentType !== '') {
            $payload['payment_type'] = $paymentType;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultGatewayOption(string $key, string $name): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'label' => PaymentGatewayCode::label($key),
            'option_key' => $key,
        ];
    }
}
