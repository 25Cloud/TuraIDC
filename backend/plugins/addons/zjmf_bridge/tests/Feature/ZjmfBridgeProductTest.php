<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Constants\ProductType;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Models\User;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;

class ZjmfBridgeProductTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.app_id' => 'zjmf-test',
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.allowed_ips' => [],
            'zjmf_bridge.signature_tolerance' => 300,
            'zjmf_bridge.system_scopes' => ['product.read'],
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_products_list_uses_fixed_business_product_type_not_menu_code(): void
    {
        [$firstGroup, $secondGroup, $thirdGroup, $product] = $this->createVisibleCatalog();
        $hiddenSecondGroup = $this->createSecondGroup($firstGroup, '隐藏二级 '.bin2hex(random_bytes(3)), false);
        $hiddenProduct = Product::query()->create($this->productPayload($hiddenSecondGroup, null, '隐藏商品', '99.00'));

        $query = [
            'product_type' => ProductType::CLOUD_SERVER,
            'first_group_id' => (int) $firstGroup->id,
            'page' => 1,
            'limit' => 10,
        ];

        $response = $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/products', query: $query))
            ->get($this->url('/zjmf/v1/products', $query), ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200);

        $ids = collect($response->json('data.list'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $item = collect($response->json('data.list'))->firstWhere('id', (int) $product->id);
        $this->assertIsArray($item);
        $this->assertSame(ProductType::CLOUD_SERVER, $item['product_type'] ?? null);
        $this->assertSame(ProductType::CLOUD_SERVER, $item['type'] ?? null);
        $this->assertSame((string) $firstGroup->code, $item['first_product_group_code'] ?? null);
        $this->assertSame((string) $firstGroup->code, $item['menu_code'] ?? null);
        $this->assertSame((int) $thirdGroup->id, $item['effective_product_group_id'] ?? null);
        $this->assertSame(3, $item['effective_product_group_level'] ?? null);
        $this->assertNotContains((int) $hiddenProduct->id, $ids);
        $this->assertSame(ProductType::CLOUD_SERVER, $item['first_group']['product_type'] ?? null);
        $this->assertNotSame((string) $firstGroup->code, $item['product_type'] ?? null);
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_hosts_cates_returns_first_second_third_group_tree_with_business_type(): void
    {
        [$firstGroup, $secondGroup, $thirdGroup] = $this->createVisibleCatalog();

        $query = [
            'product_type' => ProductType::CLOUD_SERVER,
            'first_group_id' => (int) $firstGroup->id,
        ];
        $response = $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/hosts/cates', query: $query))
            ->get($this->url('/zjmf/v1/hosts/cates', $query), ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200);

        $root = collect($response->json('data.list'))
            ->firstWhere('id', (int) $firstGroup->id);
        $this->assertIsArray($root);
        $this->assertSame((string) $firstGroup->code, $root['code'] ?? null);
        $this->assertSame(ProductType::CLOUD_SERVER, $root['product_type'] ?? null);

        $second = collect($root['children'] ?? [])->firstWhere('id', (int) $secondGroup->id);
        $this->assertIsArray($second);
        $this->assertSame(ProductType::CLOUD_SERVER, $second['product_type'] ?? null);

        $third = collect($second['children'] ?? [])->firstWhere('id', (int) $thirdGroup->id);
        $this->assertIsArray($third);
        $this->assertSame(3, $third['effective_product_group_level'] ?? null);

        $this->assertNotSame((string) $firstGroup->code, $root['product_type'] ?? null);
        $this->assertNotNull(collect($response->json('data.cate'))->firstWhere('id', (int) $firstGroup->id));
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_productsconfig_returns_detail_and_removes_sensitive_config_keys(): void
    {
        [, , , $product] = $this->createVisibleCatalog();
        $query = ['product_id' => (int) $product->id];

        $response = $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/productsconfig', query: $query))
            ->get($this->url('/zjmf/v1/productsconfig', $query), ['Accept' => 'application/json']);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.product.id', (int) $product->id)
            ->assertJsonPath('data.product.product_type', ProductType::CLOUD_SERVER)
            ->assertJsonPath('data.product.config_options.0.field', 'cpu')
            ->assertJsonMissingPath('data.product.config_options.0.api_key')
            ->assertJsonMissingPath('data.product.config_options.0.sub.0.secret');

        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_products_total_returns_read_only_quote(): void
    {
        [, , , $product] = $this->createVisibleCatalog();
        $payload = [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'config' => [
                'cpu' => '4',
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this
            ->withHeaders($this->signedHeaders('POST', '/zjmf/v1/products/total', body: $body ?: ''))
            ->postJson('/zjmf/v1/products/total', $payload);

        $response
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.product_id', (int) $product->id)
            ->assertJsonPath('data.billing_cycle', 'monthly')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.base_amount', '78.00')
            ->assertJsonPath('data.config_amount', '20.00')
            ->assertJsonPath('data.setup_fee', '10.00')
            ->assertJsonPath('data.total_amount', '108.00');
    }

    public function test_legacy_product_import_endpoints_project_catalog_without_leaking_local_group_ids(): void
    {
        [$firstGroup, $secondGroup, $thirdGroup, $product] = $this->createVisibleCatalog();
        $headers = ['Authorization' => 'Bearer '.$this->jwtFor($this->createClientUser(), ['client.read'])];

        $catalogResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/cart/all', ['Accept' => 'application/json']);

        $catalogResponse
            ->assertOk()
            ->assertJsonPath('status', 200);

        $this->assertGreaterThanOrEqual(1, (int) $catalogResponse->json('data.count'));

        $sourceGroup = collect($catalogResponse->json('data.products'))
            ->first(fn (mixed $group): bool => is_array($group)
                && in_array((int) $product->id, collect($group['products'] ?? [])->pluck('id')->map('intval')->all(), true));

        $this->assertIsArray($sourceGroup);
        $this->assertSame([$firstGroup->name, $secondGroup->name, $thirdGroup->name], $sourceGroup['source_group_path'] ?? null);
        $this->assertSame('dcimcloud', collect($sourceGroup['products'] ?? [])->firstWhere('id', (int) $product->id)['type'] ?? null);

        $sourceGroupId = $sourceGroup['id'] ?? null;
        $this->assertIsString($sourceGroupId);
        $this->assertStringStartsWith('turaidc:', $sourceGroupId);
        $this->assertNotSame((string) $thirdGroup->id, $sourceGroupId);

        $infoResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/api/product/proinfo?pids[]='.$product->id, ['Accept' => 'application/json']);

        $infoResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.info.0.id', (int) $product->id)
            ->assertJsonPath('data.info.0.name', $product->name)
            ->assertJsonPath('data.currency', 'CNY');
        $this->assertIsInt($infoResponse->json('data.info.0.location_version'));

        $detailResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/api/product/prodetail?pids[]='.$product->id, ['Accept' => 'application/json']);

        $detailResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.detail.'.$product->id.'.id', (int) $product->id)
            ->assertJsonPath('data.detail.'.$product->id.'.type', 'dcimcloud')
            ->assertJsonPath('data.detail.'.$product->id.'.source_group_id', $sourceGroupId)
            ->assertJsonPath('data.detail.'.$product->id.'.groupid', null)
            ->assertJsonMissingPath('data.detail.'.$product->id.'.purchase_requires');

        $configResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/cart/get_product_config?pid='.$product->id, ['Accept' => 'application/json']);

        $configResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.products.id', (int) $product->id)
            ->assertJsonPath('data.products.groupid', null)
            ->assertJsonPath('data.product_pricings.0.code', 'CNY')
            ->assertJsonPath('data.config_groups.0.options.0.option_name', 'CPU')
            ->assertJsonMissingPath('data.products.purchase_requires');
    }

    /**
     * @return array{0: FirstProductGroup, 1: SecondProductGroup, 2: ThirdProductGroup, 3: Product}
     */
    private function createVisibleCatalog(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroupPayload = [
            'code' => ProductType::VPS,
            'name' => 'ZJMF 云菜单 '.$suffix,
            'slug' => 'zjmf-menu-'.$suffix,
            'description' => 'ZJMF 一级菜单',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'vps',
        ];
        if (Schema::hasColumn('first_product_groups', 'product_type')) {
            $firstGroupPayload['product_type'] = ProductType::CLOUD_SERVER;
        }

        $firstGroup = FirstProductGroup::query()->create($firstGroupPayload);
        $secondGroup = $this->createSecondGroup($firstGroup, 'ZJMF 二级 '.$suffix, true);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'ZJMF 三级 '.$suffix,
            'slug' => 'zjmf-third-'.$suffix,
            'description' => 'ZJMF 三级分组',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $product = Product::query()->create($this->productPayload($secondGroup, $thirdGroup, 'ZJMF 商品 '.$suffix, '39.00'));

        return [$firstGroup, $secondGroup, $thirdGroup, $product];
    }

    private function createSecondGroup(FirstProductGroup $firstGroup, string $name, bool $visible): SecondProductGroup
    {
        $suffix = bin2hex(random_bytes(3));

        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => 'zjmf-second-'.$suffix,
            'description' => $name.' 说明',
            'sort_order' => 1,
            'is_visible' => $visible ? 1 : 0,
        ]);
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-product-'.$suffix.'@example.com',
            'phone' => '136'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Product',
            'status' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(
        SecondProductGroup $secondGroup,
        ?ThirdProductGroup $thirdGroup,
        string $name,
        string $monthlyPrice,
    ): array {
        $firstGroup = FirstProductGroup::query()->findOrFail((int) $secondGroup->first_product_group_id);

        return [
            'product_group_id' => $thirdGroup ? (int) $thirdGroup->id : (int) $secondGroup->id,
            'service_type_code' => ProductType::CLOUD_SERVER,
            'custom_display_name' => $name,
            'product_type' => ProductType::CLOUD_SERVER,
            'pricing' => [
                'monthly' => $monthlyPrice,
                'quarterly' => '117.00',
                'annually' => '468.00',
            ],
            'setup_fee' => '5.00',
            'config_options' => [
                [
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'option_type' => 6,
                    'api_key' => 'should-not-leak',
                    'sub' => [
                        [
                            'id' => '4',
                            'option_name' => '4 核',
                            'pricing' => ['monthly' => '10.00'],
                            'secret' => 'should-not-leak',
                        ],
                    ],
                ],
            ],
            'purchase_requires' => ['password' => 'should-not-leak'],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    private function signedHeaders(
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= 'nonce-'.bin2hex(random_bytes(8));
        ksort($query);
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            hash('sha256', $body),
            (string) $timestamp,
            $nonce,
        ]);

        return [
            'X-ZJMF-App-Id' => 'zjmf-test',
            'X-ZJMF-Timestamp' => (string) $timestamp,
            'X-ZJMF-Nonce' => $nonce,
            'X-ZJMF-Signature' => hash_hmac('sha256', $canonical, 'zjmf-test-secret'),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function jwtFor(User $user, array $scopes): string
    {
        return app(ZjmfTokenService::class)->issue([
            'sub' => 'client:'.(int) $user->id,
            'uid' => (int) $user->id,
            'scope' => $scopes,
        ], 7200);
    }
}
