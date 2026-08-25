<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Services\Supplier\AdminSupplierBalanceNotifier;
use App\Services\Supplier\SupplierBalanceService;
use Carbon\CarbonImmutable;
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
        $record->forceFill(['low_balance_threshold' => 20, 'balance' => 5.00])->save();

        $this->assertTrue($record->isBelowThreshold());

        $alerted = $this->invokeThreshold($service, $supplier, $record, null);
        $this->assertTrue($alerted, '首次跌破阈值应当告警');
        $this->assertNotNull($record->fresh()?->low_balance_notified_at);

        // 冷却期内再次同步不应重复告警（notifier 的 once() 断言会兜住重复调用）
        $again = $this->invokeThreshold($service, $supplier, $record->fresh(), 5.00);
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
            'low_balance_threshold' => 20,
            'balance' => 5.00,
            // 距上次告警 10 小时：默认 24 小时间隔下应静默
            'low_balance_notified_at' => CarbonImmutable::now()->subHours(10),
        ])->save();

        $this->assertFalse(
            $this->invokeThreshold($service, $supplier, $record, 5.00),
            '默认 24 小时间隔内不应重复告警'
        );

        // 把重复间隔调到 1 小时后，同一条记录应当可以再次告警。
        // 必须走 Setting::setValue()：它会顺带清掉分组级缓存，直接写库改不到已缓存的值。
        Setting::setValue('automation', 'supplier_low_balance_alert_repeat_hours', '1');

        $this->assertTrue(
            $this->invokeThreshold($service, $supplier, $record->fresh(), 5.00),
            '重复间隔调短后应恢复告警'
        );
    }

    public function test_unknown_balance_never_alerts(): void
    {
        $supplier = $this->makeSupplier();
        $notifier = Mockery::mock(AdminSupplierBalanceNotifier::class);
        $notifier->shouldNotReceive('notifyLowBalance');
        $service = new SupplierBalanceService($notifier);

        $record = $service->recordFor($supplier);
        $record->forceFill(['low_balance_threshold' => 20, 'balance' => null])->save();

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
            'low_balance_threshold' => 20,
            'balance' => 100.00,
            'low_balance_notified_at' => CarbonImmutable::now()->subMinutes(5),
        ])->save();

        $this->assertFalse($this->invokeThreshold($service, $supplier, $record, 5.00));
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
            'low_balance_threshold' => 20,
            'balance' => 1.00,
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
        ?float $previousBalance
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
