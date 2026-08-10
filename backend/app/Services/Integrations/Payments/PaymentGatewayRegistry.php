<?php

declare(strict_types=1);

namespace App\Services\Integrations\Payments;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use InvalidArgumentException;

final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function __construct(iterable $gateways = [])
    {
        foreach ($gateways as $gateway) {
            $this->register($gateway);
        }
    }

    public function register(PaymentGatewayInterface $gateway): void
    {
        $key = trim($gateway->key());

        if ($key === '') {
            throw new InvalidArgumentException('支付网关 key 不能为空');
        }

        if (isset($this->gateways[$key])) {
            throw new InvalidArgumentException("支付网关 [{$key}] 重复注册");
        }

        $this->gateways[$key] = $gateway;
    }

    public function get(string $key): PaymentGatewayInterface
    {
        $key = trim($key);

        if ($key === '' || ! isset($this->gateways[$key])) {
            throw new BusinessException('支付网关未注册或不可用');
        }

        return $this->gateways[$key];
    }

    /**
     * @return array<string, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    public function keys(): array
    {
        return array_keys($this->gateways);
    }
}
