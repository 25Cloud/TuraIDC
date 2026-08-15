<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderRegistry;
use PHPUnit\Framework\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_it_reuses_provider_descriptors_within_the_same_registry(): void
    {
        $driver = new class implements ProvidesSupplierFormSchema, UpstreamDriver
        {
            public int $schemaCalls = 0;

            public function key(): string
            {
                return 'demo_provider';
            }

            public function label(): string
            {
                return 'Demo Provider';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function supports(string $capability): bool
            {
                return false;
            }

            public function resolve(string $capability): ?object
            {
                return null;
            }

            public function supplierFormSchema(): array
            {
                $this->schemaCalls += 1;

                return [
                    'fields' => [
                        [
                            'key' => 'api_url',
                            'label' => '接口地址',
                            'type' => 'url',
                            'required' => true,
                        ],
                    ],
                ];
            }
        };

        $registry = new ProviderRegistry([$driver]);

        $this->assertSame('Demo Provider', $registry->descriptor('demo_provider')?->label);
        $this->assertSame('Demo Provider', $registry->descriptor('demo_provider')?->label);
        $this->assertSame('Demo Provider', $registry->options()[0]['label']);
        $this->assertSame('Demo Provider', $registry->descriptors()[0]->label);
        $this->assertSame(1, $driver->schemaCalls);
    }
}
