<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Services\Supplier\AdminSupplierBalanceNotifier;
use App\Services\Supplier\SupplierBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 上游余额同步与余额不足告警。
 *
 * 覆盖三条容易写错的规则：
 * - 从未同步成功（余额未知）不得触发告警，否则新接入的上游会立刻误报；
 * - 跌破阈值后有冷却期，不能每 15 分钟轰炸管理员；
 * - 余额回升到阈值以上要清除告警标记，使"再次跌破"能立即重新提醒。
 */
class SupplierBalanceSyncTest extends TestCase
{
    private const SUPPLIER_CODE = 'balance-test-supplier';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_balance_below_threshold_triggers_a_single_alert_within_cooldown(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldReceive('notifyLowBalance')->once();
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        // 金额全程用 decimal 字符串：被测代码已把浮点从金额路径上摘掉，
        // 用例里再写 5.00 这样的字面量就等于把浮点从测试侧灌回去。
        $record->forceFill(['low_balance_threshold' => '20.00', 'balance' => '5.00'])->save();

        $this->assertTrue($record->isBelowThreshold());

        $alerted = $this->invokeThreshold($service, $supplier, $record, null);
        $this->assertTrue($alerted, '首次跌破阈值应当告警');
        $this->assertNotNull($record->fresh()?->low_balance_notified_at);

        // 冷却期内再次同步不应重复告警（notifier 的 once() 断言会兜住重复调用）
        $again = $this->invokeThreshold($service, $supplier, $record->fresh(), '5.00');
        $this->assertFalse($again, '冷却期内不应重复告警');
    }

    public function test_alert_repeat_interval_follows_the_automation_setting(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldReceive('notifyLowBalance')->once();
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        $record->forceFill([
            'low_balance_threshold' => '20.00',
            'balance' => '5.00',
            // 距上次告警 10 小时：默认 24 小时间隔下应静默
            'low_balance_notified_at' => CarbonImmutable::now()->subHours(10),
        ])->save();

        $this->assertFalse(
            $this->invokeThreshold($service, $supplier, $record, '5.00'),
            '默认 24 小时间隔内不应重复告警'
        );

        // 把重复间隔调到 1 小时后，同一条记录应当可以再次告警。
        // 必须走 Setting::setValue()：它会顺带清掉分组级缓存，直接写库改不到已缓存的值。
        Setting::setValue('automation', 'supplier_low_balance_alert_repeat_hours', '1');

        $this->assertTrue(
            $this->invokeThreshold($service, $supplier, $record->fresh(), '5.00'),
            '重复间隔调短后应恢复告警'
        );
    }

    /**
     * @dataProvider centsConversions
     */
    public function test_amounts_convert_to_cents_without_floating_point(string $amount, int $expected): void
    {
        // 金额换算全程走字符串解析：本仓无 bcmath 依赖（宝塔部署的扩展清单里也没有），
        // 又不允许金额进入浮点路径，因此必须自己按小数点定标。
        $this->assertSame($expected, SupplierBalance::toCents($amount));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function centsConversions(): array
    {
        return [
            '两位小数' => ['123.45', 12345],
            '一位小数补零' => ['123.4', 12340],
            '整数无小数点' => ['20', 2000],
            '负数' => ['-5.01', -501],
            '零' => ['0.00', 0],
            '空串按 0' => ['', 0],
            '超出两位直接截断' => ['1.239', 123],
            'decimal(14,2) 上限不失精度' => ['999999999999.99', 99999999999999],
            // 按小数点朴素切分会把 "1.5e3" 算成 1.05 元，必须先还原成定点
            '科学计数法先还原' => ['1.5e3', 150000],
            '大写科学计数法' => ['2E2', 20000],
        ];
    }

    public function test_upstream_balance_is_normalized_without_scientific_notation(): void
    {
        $service = new SupplierBalanceService(Mockery::mock(AdminSupplierBalanceNotifier::class));
        $method = new \ReflectionMethod($service, 'normalizeBalanceString');
        $method->setAccessible(true);

        // 上游以 JSON 字符串返回：原样保留，这是唯一不掉精度的路径
        $this->assertSame('1234.56', $method->invoke($service, '1234.56'));
        $this->assertSame('1234.56', $method->invoke($service, ' 1234.56 '));

        // 上游以 JSON number 返回：json_decode 已经变成 float，这里只能保证不丢整数位。
        // (string) 对这个值会输出 "1.0E+12"，写进 decimal(14,2) 就是完全错误的数。
        $this->assertSame('1000000000000.00', $method->invoke($service, 1.0e12));
        $this->assertSame('100.00', $method->invoke($service, 100));
    }

    public function test_cents_round_trip_back_to_decimal_string(): void
    {
        foreach (['0.00', '0.07', '20.00', '-5.01', '999999999999.99'] as $amount) {
            $this->assertSame(
                $amount,
                SupplierBalance::centsToDecimal(SupplierBalance::toCents($amount)),
                "金额 {$amount} 往返换算应当无损"
            );
        }
    }

    public function test_sync_lock_lease_leaves_headroom_over_worst_case_upstream_time(): void
    {
        // 锁租约必须显著大于一次同步的最坏上游耗时，否则锁会在同步途中过期、
        // 另一路并发进入并用旧余额覆盖新值。改小这两个常量前请重新评估。
        $this->assertGreaterThanOrEqual(
            SupplierBalanceService::UPSTREAM_WORST_CASE_SECONDS * 3,
            SupplierBalanceService::SYNC_LOCK_SECONDS,
            '锁租约应至少留出最坏上游耗时的 3 倍余量'
        );
        // 同时不应超过定时任务自身的超时（600s），避免锁比任务活得更久
        $this->assertLessThan(600, SupplierBalanceService::SYNC_LOCK_SECONDS);
    }

    public function test_concurrent_sync_is_skipped_while_the_lock_is_held(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $service = new SupplierBalanceService($notifier);

        // 模拟另一条同步路径已持锁：本次必须直接跳过，而不是并发去打上游
        $lock = Cache::lock('supplier-balance-sync:'.(int) $supplier->id, SupplierBalanceService::SYNC_LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $result = $service->sync($supplier);
            $this->assertSame('skipped', $result['status']);
            $this->assertFalse($result['alerted']);
        } finally {
            $lock->release();
        }
    }

    public function test_unknown_balance_never_alerts(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldNotReceive('notifyLowBalance');
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        $record->forceFill(['low_balance_threshold' => '20.00', 'balance' => null])->save();

        $this->assertFalse($record->isBelowThreshold(), '余额未知应视为"未知"而非"不足"');
        $this->assertFalse($this->invokeThreshold($service, $supplier, $record, null));
    }

    public function test_recovered_balance_clears_the_alert_mark(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldNotReceive('notifyLowBalance');
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        $record->forceFill([
            'low_balance_threshold' => '20.00',
            'balance' => '100.00',
            'low_balance_notified_at' => CarbonImmutable::now()->subMinutes(5),
        ])->save();

        $this->assertFalse($this->invokeThreshold($service, $supplier, $record, '5.00'));
        $this->assertNull(
            $record->fresh()?->low_balance_notified_at,
            '余额回到阈值以上后应清除告警标记，使再次跌破能立刻提醒'
        );
    }

    public function test_alert_can_be_disabled_per_supplier(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldNotReceive('notifyLowBalance');
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        $record->forceFill([
            'low_balance_threshold' => '20.00',
            'balance' => '1.00',
            'low_balance_alert_enabled' => false,
        ])->save();

        $this->assertFalse($this->invokeThreshold($service, $supplier, $record, null));
    }

    public function test_new_record_uses_the_default_threshold(): void
    {
        $supplier = $this->makeSupplier();
        $service = new SupplierBalanceService(Mockery::mock(AdminSupplierBalanceNotifier::class));

        $record = $service->recordFor($supplier);

        $this->assertSame('20.00', (string) $record->low_balance_threshold);
        $this->assertTrue((bool) $record->low_balance_alert_enabled);
    }

    /**
     * @dataProvider insufficientBalanceMessages
     */
    public function test_insufficient_balance_messages_are_recognized(string $message, bool $expected): void
    {
        $service = new SupplierBalanceService(Mockery::mock(AdminSupplierBalanceNotifier::class));

        $this->assertSame($expected, $service->looksLikeInsufficientBalance($message));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function insufficientBalanceMessages(): array
    {
        return [
            '中文余额不足' => ['账户余额不足，请充值后重试', true],
            '中文额度不足' => ['开通失败：额度不足', true],
            '英文 insufficient' => ['Insufficient balance for this operation', true],
            '无关错误' => ['上游返回参数校验失败：hostname 不合法', false],
            '空串' => ['', false],
        ];
    }

    private function invokeThreshold(
        SupplierBalanceService $service,
        Supplier $supplier,
        SupplierBalance $record,
        ?string $previousBalance
    ): bool {
        $method = new \ReflectionMethod($service, 'handleThreshold');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $supplier, $record, $previousBalance);
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => '余额测试供应商',
            'code' => self::SUPPLIER_CODE,
            'status' => 1,
            'sort_order' => 0,
        ]);
    }

    private function cleanupFixtures(): void
    {
        $ids = Supplier::query()->where('code', self::SUPPLIER_CODE)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('supplier_balances')->whereIn('supplier_id', $ids)->delete();
            Supplier::query()->whereIn('id', $ids)->delete();
        }

        // 还原告警间隔配置，避免污染同库运行的其它用例。
        // 走 setValue 而非直接删行：它会同步清掉分组缓存，删行则清不掉。
        Setting::setValue('automation', 'supplier_low_balance_alert_repeat_hours', '24');
    }
}
