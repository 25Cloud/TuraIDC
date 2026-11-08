<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 上游流量包通道：魔方财务把我们当上游时买流量包。
 *
 * 契约来源（魔方财务 3.7.6 app/common.php + 3.8.0 FlowPacketUpstreamOrderService）：
 *   1. 主机页读 /host/header 的 data.dcim.flowpacket 拿档位；
 *   2. 付款后回调 /dcim/buy_flow_packet（id=dcimid、fid=档位 id），要 data.invoiceid；
 *   3. 立刻用 /apply_credit 付这张账单；
 *   4. 3.8.0 还会轮询 /dcim/flow_packet_order_status 判断 Paid / Pending。
 *
 * 这里只测「档位怎么下发」与「账单怎么建、状态怎么报」。
 * 真正的上游采购在账单支付之后，由 ServiceTrafficPackageService 的既有链路完成。
 */
class ZjmfUpstreamFlowPacketTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $serviceIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> 三级/二级/一级分组 id，按创建顺序记录，删除时反向处理 */
    private array $thirdGroupIds = [];

    /** @var list<int> */
    private array $secondGroupIds = [];

    /** @var list<int> */
    private array $firstGroupIds = [];

    private string $originalCatalog = '[]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCatalog = (string) Setting::getValue('traffic_package_catalog', 'items', '[]');
    }

    protected function tearDown(): void
    {
        Setting::setValue('traffic_package_catalog', 'items', $this->originalCatalog);

        if ($this->userIds !== []) {
            DB::connection()->table('invoices')->whereIn('user_id', $this->userIds)->delete();
            DB::connection()->table('orders')->whereIn('user_id', $this->userIds)->delete();
        }

        DB::connection()->table('services')->whereIn('id', $this->serviceIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('third_product_groups')->whereIn('id', $this->thirdGroupIds)->delete();
        DB::connection()->table('second_product_groups')->whereIn('id', $this->secondGroupIds)->delete();
        DB::connection()->table('first_product_groups')->whereIn('id', $this->firstGroupIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function host_header_lists_sellable_traffic_packages_from_local_catalog(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);
        $this->seedCatalog([100, 500], $service);

        $response = $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$this->apiJwt($suffix)],
        )->assertOk()->assertJsonPath('status', 200);

        $packages = (array) $response->json('data.dcim.flowpacket');
        $this->assertCount(2, $packages, '目录里的两档都应下发');

        // 魔方 3.7.6 的 buyFlowPacket 按 id 匹配档位，按 capacity 展示大小
        $this->assertSame(100, (int) $packages[0]['id']);
        $this->assertSame(100, (int) $packages[0]['capacity']);
        $this->assertSame('100G 流量包', (string) $packages[0]['name']);
        $this->assertSame(1, (int) $packages[0]['leave']);
        $this->assertSame(500, (int) $packages[1]['id']);
    }

    #[Test]
    public function host_header_hides_tiers_not_above_current_traffic_limit(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        // 实例当前 300G：100G 档位买了也不会增加流量，必须隐藏
        $service = $this->createService($user, $suffix, ['bw_limit' => 300]);
        $this->seedCatalog([100, 500], $service);

        $packages = (array) $this->getJson(
            '/api/v2/zjmf/host/header?host_id='.(int) $service->id,
            ['Authorization' => 'Bearer '.$this->apiJwt($suffix)],
        )->assertOk()->json('data.dcim.flowpacket');

        $this->assertCount(1, $packages);
        $this->assertSame(500, (int) $packages[0]['id']);
    }

    #[Test]
    public function buy_flow_packet_requires_host_and_tier(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);
        $auth = ['Authorization' => 'Bearer '.$this->apiJwt($suffix)];

        $this->postJson('/api/v2/zjmf/dcim/buy_flow_packet', ['fid' => 100], $auth)
            ->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '缺少主机 ID');

        $this->postJson('/api/v2/zjmf/dcim/buy_flow_packet', ['id' => (int) $service->id], $auth)
            ->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '缺少流量包档位');
    }

    #[Test]
    public function buy_flow_packet_rejects_tier_that_is_not_on_sale(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);
        $this->seedCatalog([100], $service);

        $this->postJson('/api/v2/zjmf/dcim/buy_flow_packet', [
            'id' => (int) $service->id,
            'fid' => 999,
        ], ['Authorization' => 'Bearer '.$this->apiJwt($suffix)])
            ->assertOk()
            ->assertJsonPath('status', 400)
            ->assertJsonPath('msg', '该流量包档位不在售，或已低于实例当前流量');
    }

    #[Test]
    public function buy_flow_packet_creates_invoice_once_per_idempotency_key(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);
        $this->seedCatalog([100], $service);

        $invoice = $this->createTrafficInvoice($user, $service, '20.00');

        $traffic = $this->createMock(ServiceTrafficPackageService::class);
        $traffic->method('sellablePackagesForService')->willReturn([[
            'id' => 100,
            'name' => '100G 流量包',
            'label' => '100G',
            'capacity' => 100,
            'price' => '20.00',
            'leave' => 1,
        ]]);
        // 同一个幂等键重复提交只能建一张账单：魔方超时重试非常常见
        $traffic->expects($this->once())
            ->method('createInvoiceForUser')
            ->with(
                $this->isInstanceOf(User::class),
                (int) $service->id,
                ['target_value' => 100],
                $this->isType('array')
            )
            ->willReturn($invoice);
        $this->swap(ServiceTrafficPackageService::class, $traffic);

        $auth = ['Authorization' => 'Bearer '.$this->apiJwt($suffix)];
        $payload = [
            'id' => (int) $service->id,
            'fid' => 100,
            'flow_packet_id' => 100,
            'idempotency_key' => 'zjmf-'.$suffix,
        ];

        $this->postJson('/api/v2/zjmf/dcim/buy_flow_packet', $payload, $auth)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.invoiceid', (int) $invoice->id)
            ->assertJsonPath('data.price', '20.00');

        $this->postJson('/api/v2/zjmf/dcim/buy_flow_packet', $payload, $auth)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.invoiceid', (int) $invoice->id);
    }

    #[Test]
    public function flow_packet_order_status_reports_unpaid_pending_and_paid(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);
        $auth = ['Authorization' => 'Bearer '.$this->apiJwt($suffix)];

        $unpaid = $this->createTrafficInvoice($user, $service, '20.00');
        $this->getJson('/api/v2/zjmf/dcim/flow_packet_order_status?invoiceid='.(int) $unpaid->id, $auth)
            ->assertOk()
            ->assertJsonPath('code', 'Unpaid');

        // 钱到了但上游还没买完 → Pending，不能误报 Paid
        $pending = $this->createTrafficInvoice($user, $service, '20.00', InvoiceStatus::PAID);
        $this->createTrafficOrder($user, $service, $pending, OrderStatus::PROCESSING);
        $this->getJson('/api/v2/zjmf/dcim/flow_packet_order_status?invoiceid='.(int) $pending->id, $auth)
            ->assertOk()
            ->assertJsonPath('code', 'Pending');

        $paid = $this->createTrafficInvoice($user, $service, '20.00', InvoiceStatus::PAID);
        $this->createTrafficOrder($user, $service, $paid, OrderStatus::COMPLETED);
        $this->getJson('/api/v2/zjmf/dcim/flow_packet_order_status?invoiceid='.(int) $paid->id, $auth)
            ->assertOk()
            ->assertJsonPath('code', 'Paid')
            ->assertJsonPath('data.invoiceid', (int) $paid->id);
    }

    #[Test]
    public function flow_packet_order_status_marks_non_traffic_invoice_as_unknown(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createApiUser($suffix);
        $service = $this->createService($user, $suffix);

        // 配置升级账单走到这个接口 = 下游问错对象，回 409 让它按「未知」处理
        $invoice = $this->createTrafficInvoice($user, $service, '20.00', InvoiceStatus::UNPAID, ['kind' => 'config_upgrade']);

        $this->getJson(
            '/api/v2/zjmf/dcim/flow_packet_order_status?invoiceid='.(int) $invoice->id,
            ['Authorization' => 'Bearer '.$this->apiJwt($suffix)],
        )
            ->assertOk()
            ->assertJsonPath('status', 409)
            ->assertJsonPath('code', 'Unknown');
    }

    /**
     * 目录条目的分类与商品类型必须和商品读值一致，否则会被分类过滤掉。
     *
     * @param  list<int>  $capacities
     */
    private function seedCatalog(array $capacities, Service $service): void
    {
        $product = Product::query()->findOrFail((int) $service->product_id);

        $items = [];
        foreach ($capacities as $index => $capacity) {
            $items[] = [
                'category_id' => (int) $product->product_group_id,
                'product_type' => (string) $product->product_type,
                'label' => $capacity.'G',
                'target_value' => $capacity,
                'price' => number_format($capacity * 0.2, 2, '.', ''),
                'enabled' => 1,
                'sort_order' => $index + 1,
            ];
        }

        Setting::setValue(
            'traffic_package_catalog',
            'items',
            (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param  array<string, mixed>  $provisionOverrides
     */
    private function createService(User $user, string $suffix, array $provisionOverrides = []): Service
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'flow-'.$suffix,
            'name' => 'Flow Root '.$suffix,
            'slug' => 'flow-root-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 0,
            'product_type' => 'cloud_server',
        ]);
        $this->firstGroupIds[] = (int) $firstGroup->id;

        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'Flow Category '.$suffix,
            'slug' => 'flow-category-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $this->secondGroupIds[] = (int) $secondGroup->id;

        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'Flow Leaf '.$suffix,
            'slug' => 'flow-leaf-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $this->thirdGroupIds[] = (int) $thirdGroup->id;

        $product = Product::query()->create([
            'name' => 'Zjmf Flow Packet '.$suffix,
            'product_type' => 'cloud_server',
            'product_group_id' => (int) $thirdGroup->id,
            'pricing' => ['monthly' => '30.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->productIds[] = (int) $product->id;

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Zjmf Flow Packet Service '.$suffix,
            'domain' => 'flow-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => $provisionOverrides,
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
        $this->serviceIds[] = (int) $service->id;

        return $service;
    }

    /**
     * @param  array<string, mixed>  $metaOverrides
     */
    private function createTrafficInvoice(
        User $user,
        Service $service,
        string $amount,
        int $status = InvoiceStatus::UNPAID,
        array $metaOverrides = [],
    ): Invoice {
        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVFP'.strtoupper(bin2hex(random_bytes(5))),
            'user_id' => (int) $user->id,
            'product_id' => (int) $service->product_id,
            'product_spec_snapshot' => '流量包',
            'product_type_snapshot' => 'server',
            'service_id' => (int) $service->id,
            'type' => 'upgrade',
            'amount' => $amount,
            'discount' => '0.00',
            'paid_amount' => $status === InvoiceStatus::PAID ? $amount : '0.00',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [
                'base_amount' => '0.00',
                'config_amount' => $amount,
                'meta' => array_merge([
                    'kind' => 'traffic_package',
                    'mode' => 'upgradeconfig',
                    'target_label' => '100G',
                    'target_snapshot' => 100,
                    'service_id' => (int) $service->id,
                ], $metaOverrides),
            ],
            'coupon_snapshot' => [],
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::PAID ? now() : null,
            'due_date' => now()->addDay(),
            'trace_id' => '',
        ]);

        return $invoice;
    }

    private function createTrafficOrder(User $user, Service $service, Invoice $invoice, int $status): Order
    {
        $order = Order::query()->create([
            'order_no' => 'ORD'.strtoupper(bin2hex(random_bytes(5))),
            'projection_type' => Order::PROJECTION_TYPE_PROVISIONING,
            'user_id' => (int) $user->id,
            'product_id' => (int) $service->product_id,
            'product_spec_snapshot' => '流量包',
            'product_type_snapshot' => 'server',
            'service_id' => (int) $service->id,
            'type' => 'upgrade',
            'amount' => (string) $invoice->amount,
            'discount' => '0.00',
            'paid_amount' => (string) $invoice->amount,
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'config_snapshot' => (array) $invoice->config_snapshot,
            'config_pricing_snapshot' => (array) $invoice->config_pricing_snapshot,
            'coupon_snapshot' => [],
            'status' => $status,
            'paid_at' => now(),
            'trace_id' => '',
        ]);

        $invoice->forceFill(['order_id' => (int) $order->id])->save();

        return $order;
    }

    private function apiJwt(string $suffix): string
    {
        return (string) $this->postJson('/api/v2/zjmf/zjmf_api_login', [
            'username' => 'zjmfapi_'.$suffix,
            'password' => 'ZjmfApi@123456',
        ])->assertOk()->json('jwt');
    }

    private function createApiUser(string $suffix): User
    {
        $user = User::query()->create([
            'email' => 'zjmffp-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Zjmf Flow Packet Api',
        ]);

        $user->forceFill([
            'api_open' => 1,
            'api_username' => 'zjmfapi_'.$suffix,
            'api_password' => Hash::make('ZjmfApi@123456'),
            'status' => 1,
        ])->save();

        $this->userIds[] = (int) $user->id;

        return $user->refresh();
    }
}
