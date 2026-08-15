<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\Admin\V2\AdminSupplierRemoteProductsResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminSupplierRemoteProductsResourceTest extends TestCase
{
    public function test_it_returns_the_full_remote_catalog_without_a_item_cap(): void
    {
        $products = array_map(
            static fn (int $id): array => [
                'id' => $id,
                'name' => 'Remote product '.$id,
                'api_key' => 'must-not-be-exposed',
                'metadata' => [
                    'region' => 'test',
                    'token' => 'must-not-be-exposed',
                ],
            ],
            range(1, 421)
        );

        $payload = (new AdminSupplierRemoteProductsResource([
            'supplier_id' => 91,
            'supplier_name' => 'Supplier 91',
            'products' => $products,
            'groups' => [
                ['name' => 'First group', 'items' => array_slice($products, 0, 210)],
                ['name' => 'Second group', 'items' => array_slice($products, 210)],
            ],
        ]))->toArray(Request::create('/api/v2/admin/suppliers/91/products'));

        $this->assertCount(421, $payload['products']);
        $this->assertSame(421, $payload['products'][420]['id']);
        $this->assertCount(210, $payload['groups'][0]['items']);
        $this->assertCount(211, $payload['groups'][1]['items']);
        $this->assertFalse($payload['truncated']);
        $this->assertArrayNotHasKey('api_key', $payload['products'][420]);
        $this->assertArrayNotHasKey('token', $payload['products'][420]['metadata']);
    }
}
