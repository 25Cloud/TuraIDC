<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Verification\Contracts\VerificationDriver;
use InvalidArgumentException;

final class VerificationDriverManager
{
    /** @var array<string, VerificationDriver> */
    private array $drivers = [];

    /**
     * 注册由容器 tag 提供的实名认证驱动。
     *
     * @param  iterable<int, VerificationDriver>  $drivers
     */
    public function __construct(
        iterable $drivers = [],
        private ?IntegrationDriverBindingResolver $bindingResolver = null,
    ) {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(VerificationDriver $driver): void
    {
        $key = trim($driver->key());

        if ($key === '') {
            throw new InvalidArgumentException('实名认证驱动 key 不能为空');
        }

        if (isset($this->drivers[$key])) {
            throw new InvalidArgumentException("实名认证驱动 [{$key}] 重复注册");
        }

        $this->drivers[$key] = $driver;
    }

    public function resolve(?string $key = null): VerificationDriver
    {
        $resolvedKey = trim((string) ($key ?? ''));
        if ($resolvedKey === '') {
            foreach ($this->bindingResolver()->verificationDriverCandidates() as $candidate) {
                if (isset($this->drivers[$candidate])) {
                    return $this->drivers[$candidate];
                }
            }

            $resolvedKey = $this->getConfiguredKey();
        }

        if (isset($this->drivers[$resolvedKey])) {
            return $this->drivers[$resolvedKey];
        }

        throw new BusinessException("实名认证驱动 [{$resolvedKey}] 未注册");
    }

    /** @return array<int, array{value: string, label: string}> */
    public function options(): array
    {
        $result = [];
        foreach ($this->drivers as $driver) {
            $result[] = ['value' => $driver->key(), 'label' => $driver->label()];
        }

        return $result;
    }

    private function getConfiguredKey(): string
    {
        return $this->bindingResolver()->verificationDriverKey();
    }

    private function bindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->bindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }
}
