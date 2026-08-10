<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Data\UpstreamProviderDescriptor;

final class ProviderRegistry
{
    /**
     * @var array<string, UpstreamDriver>
     */
    private array $drivers = [];

    /**
     * @var array<string, UpstreamProviderDescriptor>
     */
    private array $descriptorCache = [];

    /**
     * @var array<int, UpstreamProviderDescriptor>|null
     */
    private ?array $descriptorsCache = null;

    /**
     * @var array<int, array{value:string,label:string,supplier_form:array<string, mixed>}>|null
     */
    private ?array $optionsCache = null;

    /**
     * @param  iterable<int, UpstreamDriver>  $drivers
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->key()] = $driver;
        }
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->drivers);
        sort($keys);

        return $keys;
    }

    /**
     * @return array<int, array{value:string,label:string,supplier_form:array<string, mixed>}>
     */
    public function options(): array
    {
        return $this->optionsCache ??= array_map(
            fn (UpstreamProviderDescriptor $descriptor): array => $descriptor->toOption(),
            $this->descriptors()
        );
    }

    /**
     * @return array<int, UpstreamProviderDescriptor>
     */
    public function descriptors(): array
    {
        if ($this->descriptorsCache !== null) {
            return $this->descriptorsCache;
        }

        $drivers = $this->drivers;
        ksort($drivers);

        return $this->descriptorsCache = array_map(
            fn (UpstreamDriver $driver): UpstreamProviderDescriptor => $this->descriptorFromDriver($driver),
            array_values($drivers)
        );
    }

    /**
     * @return array<string, UpstreamDriver>
     */
    public function all(): array
    {
        return $this->drivers;
    }

    public function find(?string $key): ?UpstreamDriver
    {
        $normalizedKey = trim((string) $key);
        if ($normalizedKey === '') {
            return null;
        }

        return $this->drivers[$normalizedKey] ?? null;
    }

    public function descriptor(?string $key): ?UpstreamProviderDescriptor
    {
        $driver = $this->find($key);

        return $driver instanceof UpstreamDriver ? $this->descriptorFromDriver($driver) : null;
    }

    private function descriptorFromDriver(UpstreamDriver $driver): UpstreamProviderDescriptor
    {
        $key = $driver->key();

        return $this->descriptorCache[$key] ??= UpstreamProviderDescriptor::fromDriver($driver);
    }
}
