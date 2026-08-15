<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use ReflectionClass;
use Tests\TestCase;

class HostingPanelApiTransportTest extends TestCase
{
    public function test_it_disables_automatic_redirects_in_stream_context(): void
    {
        $transport = new HostingPanelApiTransport;

        $options = $this->invokePrivateMethod($transport, 'buildContextOptions', [
            'GET',
            [],
            null,
        ]);

        $this->assertSame(0, $options['http']['follow_location']);
        $this->assertSame(0, $options['http']['max_redirects']);
    }

    public function test_it_disables_automatic_redirects_in_http_client_options(): void
    {
        $transport = new HostingPanelApiTransport;

        $options = $this->invokePrivateMethod($transport, 'buildHttpClientOptions');

        $this->assertFalse($options['allow_redirects']);
    }

    public function test_explicit_authorization_header_overrides_default_jwt_scheme(): void
    {
        $transport = new HostingPanelApiTransport;

        $this->assertSame([
            'authorization: JWT default-jwt',
        ], $this->invokePrivateMethod($transport, 'buildHeaders', [
            'default-jwt',
            [],
        ]));

        $headers = $this->invokePrivateMethod($transport, 'buildHeaders', [
            'default-jwt',
            ['authorization: Bearer legacy-jwt'],
        ]);

        $this->assertSame(['authorization: Bearer legacy-jwt'], $headers);
        $this->assertTrue($this->invokePrivateMethod($transport, 'isLoginEndpoint', ['/zjmf_api_login']));
    }

    public function test_it_normalizes_base_url_without_duplicate_v1_prefix(): void
    {
        $transport = new HostingPanelApiTransport;

        $url = $this->invokePrivateMethod($transport, 'buildUrl', [
            'https://panel.example.test/v1/',
            '/v1/login_api',
            ['account' => 'demo'],
        ]);

        $this->assertSame('https://panel.example.test/v1/login_api?account=demo', $url);
    }

    public function test_generic_hosting_panel_driver_does_not_claim_zjmf_cloud_config_template_types(): void
    {
        $transport = new HostingPanelApiTransport;

        $this->assertFalse($this->invokePrivateMethod($transport, 'supportsConfigTemplate', [[
            'type' => 'dcimcloud',
        ]]));
    }

    public function test_it_supports_official_upgrade_and_promo_endpoints(): void
    {
        $transport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function login($supplier): string
            {
                return 'jwt-token';
            }

            public function request($supplier, string $method, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
            {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'query');

                return ['status' => 200, 'data' => []];
            }
        };

        $supplier = (new Supplier)->forceFill(['api_url' => 'https://panel.example.test']);

        $transport->getHostUpgradePromoPreview($supplier, 12, 'PROMO', 'jwt-token');
        $transport->removeHostUpgradePromoCode($supplier, 12, 'jwt-token');
        $transport->getHostUpgradeOptions($supplier, 12, 'jwt-token');
        $transport->previewHostUpgrade($supplier, 12, 99, 'monthly', 'jwt-token');
        $transport->applyHostUpgradePromoCode($supplier, 12, 'PROMO', 'jwt-token');
        $transport->checkoutHostUpgrade($supplier, 12, 'jwt-token');

        $this->assertSame('/v1/hosts/12/actions/upgradeconfig/promo', $transport->captured[0]['uri']);
        $this->assertSame('PUT', $transport->captured[0]['method']);
        $this->assertSame(['promo_code' => 'PROMO'], $transport->captured[0]['payload']);

        $this->assertSame('/v1/hosts/12/actions/upgradeconfig/promo', $transport->captured[1]['uri']);
        $this->assertSame('DELETE', $transport->captured[1]['method']);

        $this->assertSame('/v1/hosts/12/actions/upgrade', $transport->captured[2]['uri']);
        $this->assertSame('GET', $transport->captured[2]['method']);

        $this->assertSame('/v1/hosts/12/actions/upgrade', $transport->captured[3]['uri']);
        $this->assertSame('POST', $transport->captured[3]['method']);
        $this->assertSame(['product_id' => 99, 'billingcycle' => 'monthly'], $transport->captured[3]['payload']);

        $this->assertSame('/v1/hosts/12/actions/upgrade/promo', $transport->captured[4]['uri']);
        $this->assertSame('PUT', $transport->captured[4]['method']);

        $this->assertSame('/v1/hosts/12/actions/upgrade/checkout', $transport->captured[5]['uri']);
        $this->assertSame('POST', $transport->captured[5]['method']);
    }

    public function test_it_treats_json_unauthorized_status_as_jwt_failure(): void
    {
        $transport = new HostingPanelApiTransport;

        $this->assertTrue($this->invokePrivateMethod($transport, 'shouldForgetJwtCacheForResponse', [
            200,
            ['status' => 401],
            'jwt-token',
        ]));
        $this->assertTrue($this->invokePrivateMethod($transport, 'shouldForgetJwtCacheForResponse', [
            200,
            ['code' => '401'],
            'jwt-token',
        ]));
        $this->assertTrue($this->invokePrivateMethod($transport, 'shouldForgetJwtCacheForResponse', [
            401,
            ['status' => 200],
            'jwt-token',
        ]));
        $this->assertFalse($this->invokePrivateMethod($transport, 'shouldForgetJwtCacheForResponse', [
            200,
            ['status' => 401],
            '',
        ]));
        $this->assertFalse($this->invokePrivateMethod($transport, 'shouldForgetJwtCacheForResponse', [
            200,
            ['status' => 200],
            'jwt-token',
        ]));
    }

    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $instanceMethod = $reflection->getMethod($method);
        $instanceMethod->setAccessible(true);

        return $instanceMethod->invokeArgs($object, $arguments);
    }
}
