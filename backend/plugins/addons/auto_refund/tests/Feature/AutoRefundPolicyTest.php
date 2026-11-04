<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;
use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundPolicy;

/**
 * 策略引擎：三种退款规则（公式照抄上游 `calcRefund()`）、九条前置校验、三种退款要求。
 *
 * 金额一律按「分」比较：契约要求 `round(..., 2)`，且结果 <= 0 直接拒绝申请。
 * 边界值都是刻意挑的：刚开通（一秒钟都没用）、到期当天、已超期、按月向上取整。
 */
class AutoRefundPolicyTest extends TestCase
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

    // —— 三种规则公式 ——

    public function test_duration_rule_prorates_by_actual_period_seconds(): void
    {
        // 100 元 / 30 天周期，已用 3 天 → 100 - 100/30*3 = 90.00
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 3,
            'expires_in_days' => 27,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('90.00', $evaluation['estimated_amount']);
        $this->assertSame([], $evaluation['blocked_reasons']);
        $this->assertTrue($evaluation['refundable']);
        $this->assertStringContainsString('按时长折算', $evaluation['estimate_hint']);
    }

    public function test_duration_rule_boundary_just_opened_refunds_in_full(): void
    {
        // 刚开通：已用秒数 ≈ 0 → 金额 ≈ 首付实付
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 0,
            'expires_in_days' => 30,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('100.00', $evaluation['estimated_amount']);
        $this->assertSame([], $evaluation['blocked_reasons']);
    }

    public function test_duration_rule_boundary_on_the_expiry_day_has_nothing_left(): void
    {
        // 到期当天：已用秒数 = 周期秒数 → 金额 0.00 → amount_too_low
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 30,
            'expires_in_days' => 0,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('0.00', $evaluation['estimated_amount']);
        $this->assertContains(AutoRefundPolicy::REASON_AMOUNT_TOO_LOW, $evaluation['blocked_reasons']);
        $this->assertFalse($evaluation['refundable']);
    }

    public function test_duration_rule_boundary_after_expiry_never_goes_negative(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 40,
            'expires_in_days' => -10,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('0.00', $evaluation['estimated_amount']);
        $this->assertContains(AutoRefundPolicy::REASON_AMOUNT_TOO_LOW, $evaluation['blocked_reasons']);
        $this->assertContains(AutoRefundPolicy::REASON_EXPIRED, $evaluation['blocked_reasons']);
    }

    public function test_month_rule_rounds_used_months_up(): void
    {
        // 300 元 / 90 天周期；已用 31 天 → ceil(31/30) = 2 个月
        // 月单价 = 300 × 2592000 / 7776000 = 100 → 金额 = 300 - 200 = 100.00
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '300.00',
            'created_days_ago' => 31,
            'expires_in_days' => 59,
            'refund_rule' => AutoRefundProductRule::RULE_MONTH,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('100.00', $evaluation['estimated_amount']);
        $this->assertStringContainsString('已用 2 个月', $evaluation['estimate_hint']);
    }

    public function test_full_rule_returns_the_first_payment_amount(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 10,
            'expires_in_days' => 20,
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('100.00', $evaluation['estimated_amount']);
        $this->assertTrue($evaluation['refundable']);
    }

    public function test_pending_service_is_forced_to_full_refund_regardless_of_rule(): void
    {
        // 商品配的是「按时长」，但机器还没开通完成：上游强制按全额算
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 10,
            'expires_in_days' => 20,
            'service_status' => ServiceStatus::PENDING,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('100.00', $evaluation['estimated_amount']);
        $this->assertTrue($evaluation['forced_full']);
        $this->assertTrue($evaluation['refundable']);
    }

    public function test_amount_at_or_below_zero_is_rejected(): void
    {
        // 免费机器：首付实付 0 → 金额 0 → 拒绝
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '0.00',
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertSame('0.00', $evaluation['estimated_amount']);
        $this->assertContains(AutoRefundPolicy::REASON_AMOUNT_TOO_LOW, $evaluation['blocked_reasons']);
        $this->assertContains(AutoRefundPolicy::REASON_FREE_ORDER, $evaluation['blocked_reasons']);
        $this->assertFalse($evaluation['refundable']);
    }

    // —— 九条前置校验 ——

    public function test_check_1_missing_product_rule_blocks_the_application(): void
    {
        $fixture = $this->makeRefundService($this->user, ['with_rule' => false]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertFalse($evaluation['policy_configured']);
        $this->assertContains(AutoRefundPolicy::REASON_RULE_MISSING, $evaluation['blocked_reasons']);
    }

    public function test_check_1_disabled_product_rule_blocks_the_application(): void
    {
        $fixture = $this->makeRefundService($this->user, ['rule_enabled' => false]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertFalse($evaluation['policy_configured']);
        $this->assertContains(AutoRefundPolicy::REASON_RULE_MISSING, $evaluation['blocked_reasons']);
    }

    public function test_check_2_unpaid_first_purchase_invoice_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'invoice_status' => InvoiceStatus::UNPAID,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_UNPAID, $evaluation['blocked_reasons']);
        $this->assertFalse($evaluation['refundable']);
    }

    public function test_check_3_existing_unfinished_application_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $this->makeApplicationRow($this->user, (int) $fixture['service']->id);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_PENDING_APPLICATION, $evaluation['blocked_reasons']);
    }

    public function test_check_3_cancelled_application_does_not_block(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $this->makeApplicationRow($this->user, (int) $fixture['service']->id, [
            'status' => AutoRefundApplication::STATUS_CANCELLED,
            'active_service_id' => null,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertNotContains(AutoRefundPolicy::REASON_PENDING_APPLICATION, $evaluation['blocked_reasons']);
    }

    public function test_check_4_expired_non_pending_service_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'created_days_ago' => 40,
            'expires_in_days' => -10,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_EXPIRED, $evaluation['blocked_reasons']);
    }

    public function test_check_5_cancelled_service_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, ['service_status' => ServiceStatus::CANCELLED]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_SERVICE_STATUS, $evaluation['blocked_reasons']);
    }

    public function test_check_6_suspended_service_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, ['service_status' => ServiceStatus::SUSPENDED]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_SERVICE_STATUS, $evaluation['blocked_reasons']);
    }

    public function test_check_7_renewed_service_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $this->makeServiceInvoice($fixture['service'], 'renew');

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_RENEWED, $evaluation['blocked_reasons']);
    }

    public function test_check_7_detects_renewal_even_without_invoice_item_projection(): void
    {
        // 历史账单可能没有 invoice_items 投影行：以 invoices.type 为准同样要命中
        $fixture = $this->makeRefundService($this->user);
        $this->makeServiceInvoice($fixture['service'], 'renew', ['with_item' => false]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_RENEWED, $evaluation['blocked_reasons']);
    }

    public function test_check_8_free_first_purchase_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, ['amount' => '0.00']);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_FREE_ORDER, $evaluation['blocked_reasons']);
    }

    public function test_check_9_upgraded_service_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $this->makeServiceInvoice($fixture['service'], 'upgrade');

        $evaluation = $this->evaluate($fixture['service']->id, '9100');

        $this->assertContains(AutoRefundPolicy::REASON_UPGRADED, $evaluation['blocked_reasons']);
    }

    // —— 三种退款要求 ——

    public function test_requirement_product_first_blocks_a_second_refund_of_the_same_product(): void
    {
        $first = $this->makeRefundService($this->user, [
            'requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);
        $this->makeApplicationRow($this->user, (int) $first['service']->id, [
            'status' => AutoRefundApplication::STATUS_SETTLED,
            'active_service_id' => null,
        ]);

        // 同商品的第二台机器
        $second = $this->makeRefundService($this->user, [
            'product' => $first['product'],
            'with_rule' => false,
        ]);

        $evaluation = $this->evaluate($second['service']->id, '9101');

        $this->assertContains(AutoRefundPolicy::REASON_FIRST_ONLY_USED, $evaluation['blocked_reasons']);
    }

    public function test_requirement_category_first_blocks_a_different_product_in_the_same_root_group(): void
    {
        $groupId = $this->makeProductGroupTree('香港分组');

        $first = $this->makeRefundService($this->user, [
            'product_group_id' => $groupId,
            'requirement' => AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);
        $this->makeApplicationRow($this->user, (int) $first['service']->id, [
            'status' => AutoRefundApplication::STATUS_SETTLED,
            'active_service_id' => null,
        ]);

        // 同分组、**不同商品**的第二台机器：同商品首次拦不住它，同分组首次必须拦住
        $second = $this->makeRefundService($this->user, [
            'product_group_id' => $groupId,
            'requirement' => AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        $this->assertNotSame((int) $first['product']->id, (int) $second['product']->id);

        $evaluation = $this->evaluate($second['service']->id, '9102');

        $this->assertContains(AutoRefundPolicy::REASON_FIRST_ONLY_USED, $evaluation['blocked_reasons']);
    }

    public function test_requirement_category_first_allows_a_product_in_another_root_group(): void
    {
        $usedGroup = $this->makeProductGroupTree('香港分组');
        $otherGroup = $this->makeProductGroupTree('美国分组');

        $first = $this->makeRefundService($this->user, [
            'product_group_id' => $usedGroup,
            'requirement' => AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);
        $this->makeApplicationRow($this->user, (int) $first['service']->id, [
            'status' => AutoRefundApplication::STATUS_SETTLED,
            'active_service_id' => null,
        ]);

        $second = $this->makeRefundService($this->user, [
            'product_group_id' => $otherGroup,
            'requirement' => AutoRefundProductRule::REQUIREMENT_CATEGORY_FIRST,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        $evaluation = $this->evaluate($second['service']->id, '9103');

        $this->assertNotContains(AutoRefundPolicy::REASON_FIRST_ONLY_USED, $evaluation['blocked_reasons']);
        $this->assertTrue($evaluation['refundable']);
    }

    public function test_requirement_within_hours_rejects_after_the_window(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'created_days_ago' => 3,
            'requirement' => AutoRefundProductRule::REQUIREMENT_WITHIN_HOURS,
            'window_hours' => 24,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9104');

        $this->assertContains(AutoRefundPolicy::REASON_WINDOW_EXPIRED, $evaluation['blocked_reasons']);
    }

    public function test_requirement_within_hours_allows_inside_the_window(): void
    {
        // 开通当天、窗口 24 小时：仍在窗口内
        $fixture = $this->makeRefundService($this->user, [
            'created_days_ago' => 0,
            'requirement' => AutoRefundProductRule::REQUIREMENT_WITHIN_HOURS,
            'window_hours' => 24,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9105');

        $this->assertNotContains(AutoRefundPolicy::REASON_WINDOW_EXPIRED, $evaluation['blocked_reasons']);
        $this->assertTrue($evaluation['refundable']);
    }

    public function test_bridge_and_ticket_channels_delegate_the_requirement_check_to_upstream(): void
    {
        // api_ticket / bridge 由上游按其自身商品配置复核首退与时间窗口，
        // 本地不再用「首次」拦：那条要求留给人工/自动通道。
        $fixture = $this->makeRefundService($this->user, [
            'created_days_ago' => 3,
            'refund_type' => AutoRefundProductRule::TYPE_BRIDGE,
            'requirement' => AutoRefundProductRule::REQUIREMENT_WITHIN_HOURS,
            'window_hours' => 1,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9106');

        $this->assertNotContains(AutoRefundPolicy::REASON_WINDOW_EXPIRED, $evaluation['blocked_reasons']);
        $this->assertTrue($evaluation['refundable']);
    }

    public function test_policy_snapshot_explains_the_decision(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'amount' => '100.00',
            'created_days_ago' => 3,
            'expires_in_days' => 27,
            'refund_rule' => AutoRefundProductRule::RULE_DURATION,
        ]);

        $evaluation = $this->evaluate($fixture['service']->id, '9100');
        $snapshot = $evaluation['policy_snapshot'];

        $this->assertSame((int) $fixture['product']->id, $snapshot['product_id']);
        $this->assertTrue($snapshot['configured']);
        $this->assertSame(AutoRefundProductRule::RULE_DURATION, $snapshot['refund_rule']);
        $this->assertSame('90.00', $snapshot['resolved']['amount']);
        $this->assertSame('100.00', $snapshot['resolved']['base_amount']);
        $this->assertGreaterThan(0, $snapshot['resolved']['used_seconds']);
    }

    private function evaluate(int $serviceId, string $hostId): array
    {
        $service = Service::query()
            ->with(['product', 'order.invoice', 'invoice', 'upstreamBinding'])
            ->findOrFail($serviceId);

        return $this->app->make(AutoRefundPolicy::class)->evaluate($service, [
            'upstream_host_id' => $hostId,
            'upstream_available' => true,
            'upstream_contains' => true,
        ]);
    }
}
