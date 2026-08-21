<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Constants\InvoiceStatus;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;

class ZjmfBridgeServiceTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.token_ttl' => 7200,
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_hosts_list_and_detail_use_zjmf_token_and_remove_sensitive_data(): void
    {
        $user = $this->createClientUser('service');
        $otherUser = $this->createClientUser('other');
        $product = $this->createProduct();
        $invoice = $this->createInvoice($user);
        $service = $this->createService($user, $product, $invoice);
        $otherService = $this->createService($otherUser, $product, $this->createInvoice($otherUser));
        $headers = ['Authorization' => 'JWT '.$this->jwtFor($user, ['service.read'])];

        $listResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/hosts?limit=10', ['Accept' => 'application/json']);

        $listResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $service->id)
            ->assertJsonPath('data.list.0.hostid', (int) $service->id)
            ->assertJsonPath('data.list.0.product_type', ProductType::CLOUD_SERVER)
            ->assertJsonPath('data.list.0.status', ServiceStatus::ACTIVE)
            ->assertJsonPath('data.list.0.status_label', ServiceStatus::$labels[ServiceStatus::ACTIVE])
            ->assertJsonPath('data.list.0.ip', '203.0.113.10');

        $ids = collect($listResponse->json('data.list'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertNotContains((int) $otherService->id, $ids);

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/hosts/'.$service->id, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.host.id', (int) $service->id)
            ->assertJsonPath('data.host.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.host.provision_data.ip', '203.0.113.10')
            ->assertJsonPath('data.host.provision_data.nested.public', 'visible')
            ->assertJsonPath('data.host.locked_pricing.monthly.base_amount', '88.00')
            ->assertJsonMissingPath('data.host.provision_data.password')
            ->assertJsonMissingPath('data.host.provision_data.api_key')
            ->assertJsonMissingPath('data.host.provision_data.nested.secret')
            ->assertJsonMissingPath('data.host.locked_pricing.monthly.secret');
    }

    public function test_hosts_require_service_read_scope_and_user_ownership(): void
    {
        $user = $this->createClientUser('owner');
        $otherUser = $this->createClientUser('other-owner');
        $product = $this->createProduct();
        $otherService = $this->createService($otherUser, $product, $this->createInvoice($otherUser));

        $this
            ->withHeaders(['Authorization' => 'JWT '.$this->jwtFor($user, ['finance.read'])])
            ->get('/zjmf/v1/hosts', ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('status', 403)
            ->assertJsonPath('msg', '接口 scope 未授权');

        $this
            ->withHeaders(['Authorization' => 'JWT '.$this->jwtFor($user, ['service.read'])])
            ->get('/zjmf/v1/hosts/'.$otherService->id, ['Accept' => 'application/json'])
            ->assertStatus(404)
            ->assertJsonPath('status', 404)
            ->assertJsonPath('msg', '服务不存在');
    }

    private function createClientUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-'.$prefix.'-'.$suffix.'@example.com',
            'phone' => '136'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Service',
            'status' => 1,
        ]);
    }

    private function createProduct(): Product
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroupPayload = [
            'code' => 'zjmf_service_'.$suffix,
            'name' => 'ZJMF 服务菜单 '.$suffix,
            'slug' => 'zjmf-service-'.$suffix,
            'description' => 'ZJMF 服务菜单',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ];
        if (Schema::hasColumn('first_product_groups', 'product_type')) {
            $firstGroupPayload['product_type'] = ProductType::CLOUD_SERVER;
        }

        $firstGroup = FirstProductGroup::query()->create($firstGroupPayload);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'ZJMF 服务二级 '.$suffix,
            'slug' => 'zjmf-service-child-'.$suffix,
            'description' => 'ZJMF 服务二级',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);

        return Product::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_id' => (int) $secondGroup->id,
            'service_type_code' => (string) $firstGroup->code,
            'custom_display_name' => 'ZJMF 服务商品 '.$suffix,
            'product_type' => ProductType::CLOUD_SERVER,
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
    }

    private function createInvoice(User $user): Invoice
    {
        return Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '88.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDays(7),
            'paid_at' => now(),
        ]);
    }

    private function createService(User $user, Product $product, Invoice $invoice): Service
    {
        return Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => null,
            'invoice_id' => (int) $invoice->id,
            'name' => 'ZJMF 服务实例',
            'domain' => 'zjmf-service.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'locked_pricing' => [
                'monthly' => [
                    'enabled' => true,
                    'base_amount' => '88.00',
                    'secret' => 'should-not-leak',
                ],
            ],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'ip' => '203.0.113.10',
                'password' => 'should-not-leak',
                'api_key' => 'should-not-leak',
                'nested' => [
                    'public' => 'visible',
                    'secret' => 'should-not-leak',
                ],
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
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
