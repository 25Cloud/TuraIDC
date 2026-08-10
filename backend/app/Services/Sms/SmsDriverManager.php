<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Sms\Contracts\SmsDriver;
use InvalidArgumentException;

final class SmsDriverManager
{
    /** @var array<string, SmsDriver> */
    private array $drivers = [];

    /**
     * 注册由容器 tag 提供的短信驱动。
     *
     * @param  iterable<int, SmsDriver>  $drivers
     */
    public function __construct(
        iterable $drivers = [],
        private ?IntegrationDriverBindingResolver $bindingResolver = null,
    ) {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(SmsDriver $driver): void
    {
        $key = trim($driver->key());

        if ($key === '') {
            throw new InvalidArgumentException('短信驱动 key 不能为空');
        }

        if (isset($this->drivers[$key])) {
            throw new InvalidArgumentException("短信驱动 [{$key}] 重复注册");
        }

        $this->drivers[$key] = $driver;
    }

    public function resolve(?string $key = null): SmsDriver
    {
        $resolvedKey = trim((string) ($key ?? ''));
        if ($resolvedKey === '') {
            foreach ($this->bindingResolver()->smsDriverCandidates() as $candidate) {
                if (isset($this->drivers[$candidate])) {
                    return $this->drivers[$candidate];
                }
            }

            $resolvedKey = $this->getConfiguredKey();
        }

        if (isset($this->drivers[$resolvedKey])) {
            return $this->drivers[$resolvedKey];
        }

        throw new BusinessException("短信驱动 [{$resolvedKey}] 未注册");
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
        return $this->bindingResolver()->smsDriverKey();
    }

    private function bindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->bindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }
}
