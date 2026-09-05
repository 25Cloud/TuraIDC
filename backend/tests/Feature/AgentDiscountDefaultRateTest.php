<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\User;
use App\Services\Finance\AgentDiscountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 代理折扣全局默认折扣率回归测试。
 *
 * 折扣矩阵按「代理组 × 商品折扣组」生效，商品未挂组时既往实现直接返回无折扣，
 * 与站点「代理折扣全局生效」的预期不符。代理组现在支持 default_discount_rate
 * 兜底：矩阵命中优先，未命中（未挂组/组停用/矩阵无记录）时用默认折扣率。
 */
class AgentDiscountDefaultRateTest extends TestCase
{
    private AgentDiscountService $service;

    /** @var list<int> */
    private array $agentGroupIds = [];

    /** @var list<int> */
    private array $productGroupIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentDiscountService;
    }

    /**
     * 共享测试库上的既有列表类断言按索引取值，残留行会污染它们，测试结束时清理本类 fixture。
     */
    protected function tearDown(): void
    {
        DB::connection()->table('services')->whereIn('product_id', $this->productIds)->delete();
        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('users')->whereIn('id', $this->userIds)->delete();
        DB::connection()->table('agent_group_discounts')->whereIn('agent_group_id', $this->agentGroupIds)->delete();
        DB::connection()->table('product_discount_groups')->whereIn('id', $this->productGroupIds)->delete();
        DB::connection()->table('agent_groups')->whereIn('id', $this->agentGroupIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function default_rate_applies_to_products_without_a_discount_group(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = $this->createAgentGroup($suffix, '85.00');
        $product = $this->createProduct($suffix, null);
        $user = $this->createUserWithAgentGroup($suffix, (int) $group->id);

        $pricing = $this->service->apply($product, $user, 100.0);

        $this->assertSame(85.0, (float) $pricing['discount_rate']);
        $this->assertSame(85.0, (float) $pricing['discounted_amount']);
        $this->assertSame('default', $pricing['discount_source']);
        $this->assertNull($pricing['product_discount_group_id']);
    }

    #[Test]
    public function matrix_entry_takes_priority_over_the_default_rate(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = $this->createAgentGroup($suffix, '85.00');
        $productGroup = ProductDiscountGroup::query()->create([
            'name' => '折扣组 '.$suffix,
            'code' => 'dg-'.$suffix,
            'min_discount_rate' => 50.00,
            'cost_rate' => 0.00,
            'status' => 1,
            'sort_order' => 0,
        ]);
        AgentGroupDiscount::query()->create([
            'agent_group_id' => (int) $group->id,
            'product_discount_group_id' => (int) $productGroup->id,
            'discount_rate' => 70.00,
        ]);
        $this->productGroupIds[] = (int) $productGroup->id;
        $product = $this->createProduct($suffix, (int) $productGroup->id);
        $user = $this->createUserWithAgentGroup($suffix, (int) $group->id);

        $pricing = $this->service->apply($product, $user, 100.0);

        $this->assertSame(70.0, (float) $pricing['discount_rate']);
        $this->assertSame('matrix', $pricing['discount_source']);
        $this->assertSame((int) $productGroup->id, (int) $pricing['product_discount_group_id']);
    }

    #[Test]
    public function default_rate_fills_in_when_the_matrix_has_no_entry_for_the_group(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = $this->createAgentGroup($suffix, '85.00');
        $productGroup = ProductDiscountGroup::query()->create([
            'name' => '无矩阵折扣组 '.$suffix,
            'code' => 'dg-empty-'.$suffix,
            'min_discount_rate' => 50.00,
            'cost_rate' => 0.00,
            'status' => 1,
            'sort_order' => 0,
        ]);
        $this->productGroupIds[] = (int) $productGroup->id;
        $product = $this->createProduct($suffix, (int) $productGroup->id);
        $user = $this->createUserWithAgentGroup($suffix, (int) $group->id);

        $pricing = $this->service->apply($product, $user, 100.0);

        $this->assertSame(85.0, (float) $pricing['discount_rate']);
        $this->assertSame('default', $pricing['discount_source']);
    }

    #[Test]
    public function no_discount_is_applied_without_a_default_rate(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = $this->createAgentGroup($suffix, null);
        $product = $this->createProduct($suffix, null);
        $user = $this->createUserWithAgentGroup($suffix, (int) $group->id);

        $pricing = $this->service->apply($product, $user, 100.0);

        $this->assertSame(100.0, (float) $pricing['discount_rate']);
        $this->assertSame(100.0, (float) $pricing['discounted_amount']);
        $this->assertNull($pricing['discount_source']);
    }

    #[Test]
    public function disabled_agent_group_never_gets_a_discount(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = $this->createAgentGroup($suffix, '85.00', 0);
        $product = $this->createProduct($suffix, null);
        $user = $this->createUserWithAgentGroup($suffix, (int) $group->id);

        $pricing = $this->service->apply($product, $user, 100.0);

        $this->assertSame(100.0, (float) $pricing['discount_rate']);
        $this->assertNull($pricing['discount_source']);
    }

    private function createAgentGroup(string $suffix, ?string $defaultRate, int $status = 1): AgentGroup
    {
        $group = AgentGroup::query()->create([
            'name' => '默认折扣组 '.$suffix,
            'code' => 'ag-'.$suffix,
            'status' => $status,
            'default_discount_rate' => $defaultRate,
            'sort_order' => 0,
            'remark' => '',
        ]);
        $this->agentGroupIds[] = (int) $group->id;

        return $group;
    }

    private function createProduct(string $suffix, ?int $discountGroupId): Product
    {
        $product = Product::query()->create([
            'name' => '默认折扣商品 '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '100.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        if ($discountGroupId !== null) {
            DB::connection()->table('products')->updateOrInsert(
                ['id' => (int) $product->id],
                ['product_discount_group_id' => $discountGroupId],
            );
        }

        $this->productIds[] = (int) $product->id;

        return $product->refresh();
    }

    private function createUserWithAgentGroup(string $suffix, int $agentGroupId): User
    {
        $user = User::query()->create([
            'email' => 'agent-default-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Agent Default Rate',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        DB::connection()->table('users')->updateOrInsert(
            ['id' => (int) $user->id],
            [
                'password' => Hash::make('Temp@123456'),
                'agent_group_id' => $agentGroupId,
            ],
        );

        $this->userIds[] = (int) $user->id;

        return $user->refresh();
    }
}
