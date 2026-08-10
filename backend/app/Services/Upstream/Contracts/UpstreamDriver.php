<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

interface UpstreamDriver
{
    public function key(): string;

    public function label(): string;

    /**
     * 返回当前 provider 明确声明支持的能力接口。
     *
     * @return array<int, class-string>
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    public function resolve(string $capability): ?object;
}
