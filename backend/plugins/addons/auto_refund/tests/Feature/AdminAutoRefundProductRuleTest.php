<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 管理端「商品退款策略」两条接口：列表（含未配置商品）与保存。
 *
 * 权限口径与台账一致：查询走 `invoice.list`，写入走 `invoice.manage`。
 * 列表以商品为左表是刻意设计——没配过策略的商品必须也能看到，否则运营不知道还差哪些。
 */
class AdminAutoRefundProductRuleTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsAutoRefundAddon;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->installAutoRefundAddon();
        $this->user = $this->makeRefundUser();
    }

    public function test_index_lists_configured_and_unconfigured_products(): void
    {
        $configured = $this->makeRefundService($this->user, [
            'refund_type' => AutoRefundProductRule::TYPE_AUTO,
            'refund_rule' => AutoRefundProductRule::RULE_MONTH,
            'requirement' => AutoRefundProductRule::REQUIREMENT_WITHIN_HOURS,
            'window_hours' => 48,
        ]);
        $unconfigured = $this->makeRefundService($this->user, ['with_rule' => false]);

        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_LIST]));

        // 商品库是共享的（测试库无 RefreshDatabase，历次用例会累积商品），
        // 默认第一页未必包含刚造的两个商品，所以按页取全量再按 product_id 定位，
        // 否则这个用例会随「库里已有多少商品」随机红。
        $rows = $this->collectRuleRowsByProductId();

        $configuredRow = $rows[(int) $configured['product']->id];
        $this->assertTrue($configuredRow['configured']);
        $this->assertSame(AutoRefundProductRule::TYPE_AUTO, $configuredRow['refund_type']);
        $this->assertSame('自动退款', $configuredRow['refund_type_label']);
        $this->assertSame(AutoRefundProductRule::RULE_MONTH, $configuredRow['refund_rule']);
        $this->assertSame(48, $configuredRow['window_hours']);
        $this->assertTrue($configuredRow['enabled']);

        $unconfiguredRow = $rows[(int) $unconfigured['product']->id];
        $this->assertFalse($unconfiguredRow['configured']);
        $this->assertNull($unconfiguredRow['refund_type']);
        $this->assertFalse($unconfiguredRow['enabled']);
        $this->assertSame('未配置', $unconfiguredRow['enabled_label']);

        // status 过滤：未配置只返回没策略行的商品，已配置只返回有策略行的。
        // 不断言 total：共享测试库里可能有别的商品，断言「本用例这两件在不在」才稳；
        // 同样翻页取全量，避免商品累计到几百条后这两件掉到第二页。
        $unconfiguredIds = $this->collectRuleRowsByProductId(['status' => 'unconfigured'])
            ->pluck('product_id')
            ->all();
        $this->assertContains((int) $unconfigured['product']->id, $unconfiguredIds);
        $this->assertNotContains((int) $configured['product']->id, $unconfiguredIds);

        $configuredIds = $this->collectRuleRowsByProductId(['status' => 'configured'])
            ->pluck('product_id')
            ->all();
        $this->assertContains((int) $configured['product']->id, $configuredIds);
        $this->assertNotContains((int) $unconfigured['product']->id, $configuredIds);
    }

    public function test_index_filters_by_keyword_and_exposes_the_first_product_group(): void
    {
        $groupId = $this->makeProductGroupTree('香港机房');
        $fixture = $this->makeRefundService($this->user, ['product_group_id' => $groupId]);

        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_LIST]));

        $response = $this->getJson('/api/v2/admin/auto-refund/product-rules?keyword=香港')
            ->assertOk();

        $row = collect($response->json('data.list'))->firstWhere('product_id', (int) $fixture['product']->id);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['first_group_name']);
        $this->assertStringContainsString('香港机房-一级', (string) $row['first_group_name']);
    }

    public function test_update_creates_then_updates_the_rule_and_records_the_operator(): void
    {
        $fixture = $this->makeRefundService($this->user, ['with_rule' => false]);
        $productId = (int) $fixture['product']->id;

        $admin = $this->makeAdmin([AdminPermissions::INVOICE_LIST, AdminPermissions::INVOICE_MANAGE]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v2/admin/auto-refund/product-rules/'.$productId, [
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
            'requirement' => AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST,
            'window_hours' => 0,
            'enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.refund_type', AutoRefundProductRule::TYPE_API_TICKET)
            ->assertJsonPath('data.requirement_label', '同分组首次退款');

        $rule = AutoRefundProductRule::query()->where('product_id', $productId)->firstOrFail();
        $this->assertSame(AutoRefundProductRule::TYPE_API_TICKET, (string) $rule->refund_type);
        $this->assertSame((int) $admin->id, (int) $rule->operator_id);
        $this->assertTrue((bool) $rule->enabled);

        // 同一商品只有一行：再存一次是更新，不是新增
        $this->putJson('/api/v2/admin/auto-refund/product-rules/'.$productId, [
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
            'requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
            'window_hours' => 12,
            'enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.refund_rule', AutoRefundProductRule::RULE_FULL);

        $this->assertSame(1, AutoRefundProductRule::query()->where('product_id', $productId)->count());
        $this->assertSame(12, (int) AutoRefundProductRule::query()->where('product_id', $productId)->value('window_hours'));
    }

    public function test_update_rejects_unknown_enum_values(): void
    {
        $fixture = $this->makeRefundService($this->user);
        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_MANAGE]));

        $this->putJson('/api/v2/admin/auto-refund/product-rules/'.(int) $fixture['product']->id, [
            'refund_type' => 'not-a-type',
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
            'requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    public function test_update_returns_404_for_unknown_product(): void
    {
        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_MANAGE]));

        $this->putJson('/api/v2/admin/auto-refund/product-rules/999999999', [
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
            'requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);
    }

    public function test_permissions_gate_reads_and_writes(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $productId = (int) $fixture['product']->id;

        // 只有看账单的权限：能读策略列表
        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_LIST]));
        $this->getJson('/api/v2/admin/auto-refund/product-rules')->assertOk();

        // 没有 invoice.manage：写策略被拒
        $this->putJson('/api/v2/admin/auto-refund/product-rules/'.$productId, [
            'refund_type' => AutoRefundProductRule::TYPE_AUTO,
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
            'requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
        ])->assertStatus(403)->assertJsonPath('code', 40300);

        $this->assertSame(
            AutoRefundProductRule::TYPE_BRIDGE,
            (string) AutoRefundProductRule::query()->where('product_id', $productId)->value('refund_type'),
        );

        // 连 invoice.list 都没有：列表也看不到
        Sanctum::actingAs($this->makeAdmin([AdminPermissions::DASHBOARD_VIEW]));
        $this->getJson('/api/v2/admin/auto-refund/product-rules')->assertStatus(403);
    }

    /**
     * 按页取全量规则行并按 product_id 建索引。
     *
     * 测试库是共享的，商品数量会随其他用例增长，默认第一页不保证含本用例刚造的商品；
     * 这里翻页直到取完（或命中页大小上限），既不依赖库规模也不放宽断言。
     *
     * @param  array<string, string>  $query  额外过滤条件（如 status）
     * @return Collection<int, array<string, mixed>>
     */
    private function collectRuleRowsByProductId(array $query = []): Collection
    {
        $rows = collect();

        for ($page = 1; $page <= 20; $page++) {
            $url = '/api/v2/admin/auto-refund/product-rules?'.http_build_query(array_merge($query, [
                'page' => $page,
                'page_size' => 100,
            ]));

            $response = $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('code', 0);

            $list = collect($response->json('data.list') ?? []);

            if ($list->isEmpty()) {
                break;
            }

            $rows = $rows->merge($list);

            if ($list->count() < 100) {
                break;
            }
        }

        return $rows->keyBy('product_id');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'auto-refund-rule-role-'.$suffix,
            'label' => 'Auto Refund Rule Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'auto-refund-rule-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'AR Rule Admin',
            'email' => 'auto-refund-rule-admin-'.$suffix.'@example.test',
            'status' => 1,
        ]);
    }
}
