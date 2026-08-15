<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostingPanelApiTransportParallelGetTest extends TestCase
{
    public function test_parallel_get_accepts_numeric_request_keys(): void
    {
        Http::fake([
            'https://supplier.example.test/v1/hosts/101' => Http::response(['status' => 200, 'data' => ['id' => 101]], 200),
            'https://supplier.example.test/v1/hosts/202' => Http::response(['status' => 200, 'data' => ['id' => 202]], 200),
        ]);

        $transport = new HostingPanelApiTransport;
        $supplier = (new Supplier)->forceFill([
            'id' => 1,
            'api_url' => 'https://supplier.example.test',
        ]);

        $result = $transport->parallelGet($supplier, [
            101 => ['uri' => '/v1/hosts/101'],
            202 => ['uri' => '/v1/hosts/202'],
        ], 'test-jwt');

        $this->assertSame(200, $result['101']['status_code']);
        $this->assertSame(101, $result['101']['response']['data']['id']);
        $this->assertSame(200, $result['202']['status_code']);
        $this->assertSame(202, $result['202']['response']['data']['id']);
    }
}
