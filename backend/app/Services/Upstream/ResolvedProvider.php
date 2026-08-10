<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Exceptions\BusinessException;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Data\UpstreamProviderDescriptor;

final class ResolvedProvider
{
    public function __construct(
        private readonly ?string $rawKey,
        private readonly ?string $normalizedKey,
        private readonly ?UpstreamDriver $driver,
    ) {}

    public function rawKey(): ?string
    {
        return $this->rawKey;
    }

    public function key(): ?string
    {
        return $this->normalizedKey;
    }

    public function label(): string
    {
        if ($this->driver instanceof UpstreamDriver) {
            return $this->driver->label();
        }

        $key = trim((string) ($this->normalizedKey ?? $this->rawKey ?? ''));

        return $key !== '' ? $key : '未配置接口';
    }

    public function isResolved(): bool
    {
        return $this->driver instanceof UpstreamDriver;
    }

    /**
     * 返回当前已解析 provider 的能力接口列表。
     *
     * @return array<int, class-string>
     */
    public function capabilities(): array
    {
        return $this->driver instanceof UpstreamDriver
            ? $this->driver->capabilities()
            : [];
    }

    public function supports(string $capability): bool
    {
        return $this->driver instanceof UpstreamDriver
            && $this->driver->supports($capability);
    }

    public function descriptor(): UpstreamProviderDescriptor
    {
        if ($this->driver instanceof UpstreamDriver) {
            return UpstreamProviderDescriptor::fromDriver($this->driver);
        }

        return new UpstreamProviderDescriptor(
            key: trim((string) ($this->normalizedKey ?? $this->rawKey ?? '')),
            label: $this->label(),
        );
    }

    public function maybe(string $capability): ?object
    {
        if (! $this->driver instanceof UpstreamDriver) {
            return null;
        }

        return $this->driver->resolve($capability);
    }

    public function require(string $capability, string $message = '当前接口不支持该能力'): object
    {
        $resolved = $this->maybe($capability);
        if ($resolved instanceof \stdClass || is_object($resolved)) {
            return $resolved;
        }

        throw new BusinessException($message, 42200);
    }
}
