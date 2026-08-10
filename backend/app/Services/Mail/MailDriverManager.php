<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Mail\Contracts\MailDriver;
use InvalidArgumentException;

final class MailDriverManager
{
    /** @var array<string, MailDriver> */
    private array $drivers = [];

    /**
     * @param  iterable<int, MailDriver>  $drivers
     */
    public function __construct(
        iterable $drivers = [],
        private ?IntegrationDriverBindingResolver $bindingResolver = null,
    ) {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(MailDriver $driver): void
    {
        $key = trim($driver->key());

        if ($key === '') {
            throw new InvalidArgumentException('邮件驱动 key 不能为空');
        }

        if (isset($this->drivers[$key])) {
            throw new InvalidArgumentException("邮件驱动 [{$key}] 重复注册");
        }

        $this->drivers[$key] = $driver;
    }

    public function resolve(?string $key = null): MailDriver
    {
        $resolvedKey = trim((string) ($key ?? ''));
        if ($resolvedKey === '') {
            foreach ($this->bindingResolver()->mailDriverCandidates() as $candidate) {
                if (isset($this->drivers[$candidate])) {
                    return $this->drivers[$candidate];
                }
            }

            $resolvedKey = $this->getConfiguredKey();
        }

        if (isset($this->drivers[$resolvedKey])) {
            return $this->drivers[$resolvedKey];
        }

        throw new BusinessException("邮件驱动 [{$resolvedKey}] 未注册", 42200);
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
        return $this->bindingResolver()->mailDriverKey();
    }

    private function bindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->bindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }
}
