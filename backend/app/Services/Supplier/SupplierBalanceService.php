<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Models\SupplierBalanceLog;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 上游余额同步与余额不足预警。
 *
 * 上游余额此前只能由管理员手动点击查询、不落库，既无法预警也看不到历史。本服务
 * 把余额固化到 supplier_balances，并在跌破阈值时通知管理员。
 *
 * 两条触发路径互不干扰：
 * - 定时任务每个心跳槽（15 分钟）全量同步一次，是主力；
 * - 上游开通完成后针对该供应商单独同步一次（开通才是真正扣减上游余额的动作），
 *   这一次是"额外"的，不改写定时任务的节奏——定时任务不看"最近是否刚同步过"，
 *   到点照常执行。
 */
class SupplierBalanceService
{
    /** 重复告警间隔的兜底值（小时），配置读取异常时使用 */
    public const DEFAULT_ALERT_REPEAT_HOURS = 24;

    /**
     * 单供应商同步的互斥锁租约（秒）。
     *
     * 必须大于一次同步的最坏耗时，否则锁会在同步途中过期、另一路进来并发写入，
     * 慢请求随后用旧余额覆盖新值。依据：上游 HTTP 超时 30s（Http::timeout(30)），
     * 一次余额查询最多两跳（登录 + 取余额）= 60s，再留 4 倍余量应对重试与慢库。
     * 同时保持小于定时任务 600s 的超时，避免锁比任务活得还久。
     */
    public const SYNC_LOCK_SECONDS = 300;

    /** 一次同步的最坏上游耗时预算（秒），用于校验锁租约留有足够余量 */
    public const UPSTREAM_WORST_CASE_SECONDS = 60;

    public function __construct(
        private readonly AdminSupplierBalanceNotifier $notifier,
    ) {}

    /**
     * 全量同步所有启用供应商的余额。
     *
     * 单个供应商失败不中断整批：上游接口不可用是常态，一个挂掉不该拖垮其它供应商。
     *
     * @return array<string, mixed>
     */
    public function syncAll(): array
    {
        // 只取真正接了上游的供应商：没有启用绑定的供应商拿不到 provider，
        // 每轮都去解析一次纯属浪费，供应商多时这部分开销很可观。
        $suppliers = Supplier::query()
            ->enabled()
            ->when(Schema::hasTable('supplier_plugin_bindings'), fn ($query) => $query->whereExists(
                fn ($sub) => $sub->select(DB::raw(1))
                    ->from('supplier_plugin_bindings')
                    ->whereColumn('supplier_plugin_bindings.supplier_id', 'suppliers.id')
                    ->where('supplier_plugin_bindings.status', 1)
            ))
            ->orderBy('id')
            ->get();
        $summary = ['total' => $suppliers->count(), 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'alerted' => 0];

        foreach ($suppliers as $supplier) {
            $result = $this->sync($supplier, SupplierBalanceLog::SOURCE_SCHEDULE);
            $key = (string) ($result['status'] ?? 'skipped');
            if ($key === 'success') {
                $summary['synced']++;
            } elseif ($key === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
            if (($result['alerted'] ?? false) === true) {
                $summary['alerted']++;
            }
        }

        return $summary;
    }

    /**
     * 同步单个供应商的余额，并按阈值决定是否告警。
     *
     * @return array<string, mixed>
     */
    public function sync(
        Supplier $supplier,
        string $source = SupplierBalanceLog::SOURCE_SCHEDULE,
        ?int $orderId = null
    ): array {
        // 定时同步与开通后触发的队列任务可能并发跑同一个供应商：两边各自请求上游后
        // 回写，先返回的慢请求会用旧余额盖掉新余额，还会产生方向错误的流水与告警。
        // 按 supplier_id 取锁串行化；拿不到锁说明另一路正在同步，本次直接跳过即可。
        $lock = Cache::lock('supplier-balance-sync:'.(int) $supplier->id, self::SYNC_LOCK_SECONDS);
        if (! $lock->get()) {
            return ['status' => 'skipped', 'message' => '该供应商余额正在同步中', 'alerted' => false];
        }

        try {
            return $this->syncLocked($supplier, $source, $orderId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function syncLocked(Supplier $supplier, string $source, ?int $orderId): array
    {
        $record = $this->recordFor($supplier);
        $record->forceFill(['last_attempted_at' => now()])->save();

        try {
            $payload = $this->fetchBalance($supplier);
        } catch (\Throwable $exception) {
            $message = $exception instanceof BusinessException
                ? $exception->getMessage()
                : '上游余额查询失败';

            $record->forceFill([
                'last_sync_status' => 'failed',
                'last_sync_error' => Str::limit($message, 500, ''),
            ])->save();

            Log::warning('[上游余额同步] 查询失败', [
                'supplier_id' => (int) $supplier->id,
                'supplier_name' => (string) $supplier->name,
                'message' => $message,
            ]);

            return ['status' => 'failed', 'message' => $message, 'alerted' => false];
        }

        $previousBalance = $record->balance === null ? null : (string) $record->balance;
        $record->forceFill([
            'provider_key' => $payload['provider_key'],
            'balance' => $payload['balance'],
            'currency' => $payload['currency'],
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_error' => null,
        ])->save();

        $this->recordChangeLog($supplier, $record, $previousBalance, $source, $orderId);
        $alerted = $this->handleThreshold($supplier, $record, $previousBalance);

        return [
            'status' => 'success',
            'balance' => (string) $record->balance,
            'previous_balance' => $previousBalance,
            'alerted' => $alerted,
        ];
    }

    /**
     * 取得供应商的余额台账行，没有则按默认阈值建一条。
     */
    public function recordFor(Supplier $supplier): SupplierBalance
    {
        return SupplierBalance::query()->firstOrCreate(
            ['supplier_id' => (int) $supplier->id],
            [
                'low_balance_threshold' => SupplierBalance::DEFAULT_LOW_BALANCE_THRESHOLD,
                'low_balance_alert_enabled' => true,
            ],
        );
    }

    /**
     * 判断错误信息是否属于"上游余额/额度不足"。
     *
     * 各家上游文案不统一，这里按关键词识别；命中即视为余额问题，用于把开通失败
     * 定性成可由管理员充值解决的问题，而不是淹没在通用失败日志里。
     */
    public function looksLikeInsufficientBalance(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        foreach (['余额不足', '额度不足', '余额不够', '账户余额', '请充值', '充值后', 'insufficient', 'not enough', 'no enough', 'low balance', 'balance is not'] as $needle) {
            if (str_contains($normalized, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 上游开通因余额不足而失败时通知管理员。
     */
    public function notifyProvisionFailure(Supplier $supplier, array $context): void
    {
        $record = $this->recordFor($supplier);

        $this->notifier->notifyProvisionFailed($supplier, $record, $context);
    }

    /**
     * @return array{provider_key: ?string, balance: string, currency: ?string}
     */
    private function fetchBalance(Supplier $supplier): array
    {
        $runtimeSupplier = app(PluginBindingResolver::class)->supplierWithRuntimeCredentials($supplier);
        $provider = app(ProviderResolver::class)->resolveForSupplier($runtimeSupplier);
        $renewal = $provider->require(ProvidesRenewal::class, '当前供应商暂不支持余额查询');
        $result = $renewal->getBalance($runtimeSupplier);

        $payload = is_array($result['data'] ?? null) ? array_replace($result, $result['data']) : $result;
        $currency = $payload['currency'] ?? null;
        if (is_array($currency)) {
            $currency = $currency['code'] ?? ($currency['name'] ?? null);
        }

        // 余额缺失或非数值时按"查询失败"处理，绝不降级成 0。
        // 若默认成 0：last_sync_status 会被记成 success、isBelowThreshold() 立刻为真、
        // 触发一封假的余额不足告警，还会写一条 delta 为大负数的流水污染对账数据。
        // 模型层区分"未知（null）"与"不足"，取值层必须守住同一口径。
        $rawBalance = $payload['balance'] ?? null;
        if ($rawBalance === null || $rawBalance === '' || ! is_numeric($rawBalance)) {
            throw new BusinessException('上游未返回可用的余额数值', 42200);
        }

        return [
            'provider_key' => $this->resolveProviderKey($supplier),
            'balance' => $this->normalizeBalanceString($rawBalance),
            'currency' => $currency === null ? null : Str::limit((string) $currency, 20, ''),
        ];
    }

    /**
     * 把上游返回的余额规整成 decimal 字符串。
     *
     * 上游以 JSON 字符串返回时原样保留——这是唯一不损失精度的路径。
     * 以 JSON number 返回时 json_decode 早已把它变成 float，精度在到达本方法之前
     * 就已经丢了，这里挽回不了；但至少不能用 (string) 直接转：大额余额会被输出成
     * "1.0E+12"，连整数位都没了，写进 decimal(14,2) 会变成完全错误的数。
     * 定点格式化到两位小数，与列定义对齐。
     *
     * @param  int|float|string  $rawBalance  已通过 is_numeric 校验
     */
    private function normalizeBalanceString(mixed $rawBalance): string
    {
        return is_string($rawBalance) ? trim($rawBalance) : sprintf('%.2F', $rawBalance);
    }

    /**
     * 写入额度变更流水。
     *
     * 只在余额真正变化时落一行：定时同步每 15 分钟一次，若无条件记录，
     * 单个供应商一天就是 96 行没有信息量的数据。首次同步（此前余额未知）
     * 记一行作为起点。
     */
    private function recordChangeLog(
        Supplier $supplier,
        SupplierBalance $record,
        ?string $previousBalance,
        string $source,
        ?int $orderId
    ): void {
        if ($record->balance === null) {
            return;
        }

        // 金额一律换成整数分再比较与相减：浮点相减会产生 0.009999… 这类残差，
        // 既可能让"没变化"被误判成变化而反复记流水，也会让 delta 带上噪声。
        $currentCents = SupplierBalance::toCents($record->balance);
        $previousCents = $previousBalance === null ? null : SupplierBalance::toCents($previousBalance);

        if ($previousCents !== null && $currentCents === $previousCents) {
            return;
        }

        try {
            SupplierBalanceLog::query()->create([
                'supplier_id' => (int) $supplier->id,
                'balance' => $record->balance,
                'previous_balance' => $previousBalance,
                // 差值在分单位上做减法，再转回 decimal 字符串写库；
                // 除以 100 会得到浮点值，金额不走浮点路径。
                'delta' => $previousCents === null
                    ? null
                    : SupplierBalance::centsToDecimal($currentCents - $previousCents),
                'currency' => $record->currency,
                'source' => $source,
                'order_id' => $orderId,
                'recorded_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // 流水是旁路数据，写失败不能影响余额同步与告警本身
            Log::warning('[上游余额同步] 变更流水写入失败', [
                'supplier_id' => (int) $supplier->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 清理过期的额度变更流水。
     *
     * @return int 删除行数
     */
    public function purgeExpiredLogs(int $retentionDays): int
    {
        if ($retentionDays < 0 || ! Schema::hasTable('supplier_balance_logs')) {
            return 0;
        }

        $threshold = now()->subDays($retentionDays);
        $deleted = 0;

        // 分批删除：一次性 delete 大表会长时间持锁，这里每批 1000 行，
        // 与其它清理任务的口径一致。
        do {
            $batch = SupplierBalanceLog::query()
                ->where('recorded_at', '<', $threshold)
                ->limit(1000)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /**
     * 删除供应商时清掉其余额台账与流水。
     *
     * 不做这一步的话，供应商删除后这两张表会留下永远不会被再引用的孤儿数据，
     * 而且新供应商若复用了同一个自增 ID，还会读到上一任的余额。
     *
     * @return array{balances: int, logs: int}
     */
    public function purgeSupplierData(int $supplierId): array
    {
        $balances = Schema::hasTable('supplier_balances')
            ? SupplierBalance::query()->where('supplier_id', $supplierId)->delete()
            : 0;
        $logs = Schema::hasTable('supplier_balance_logs')
            ? SupplierBalanceLog::query()->where('supplier_id', $supplierId)->delete()
            : 0;

        return ['balances' => $balances, 'logs' => $logs];
    }

    /**
     * 重复告警间隔（分钟），取自管理端「自动化策略」，默认 24 小时。
     *
     * 配置读取失败时退回默认值：告警节流拿不到配置也不该让整轮同步崩掉。
     */
    /**
     * 刻意不做实例级缓存：Setting 已有分组级缓存，真正的数据库查询只发生一次，
     * 这里省下的只是数组组装。换来的却是配置改动在长驻进程内不生效——
     * 那类"改了设置没反应"的问题远比这点开销难排查。
     */
    private function alertRepeatMinutes(): int
    {
        try {
            $hours = (int) (app(SettingService::class)->getAutomationConfig()['supplier_low_balance_alert_repeat_hours']
                ?? self::DEFAULT_ALERT_REPEAT_HOURS);
        } catch (\Throwable) {
            $hours = self::DEFAULT_ALERT_REPEAT_HOURS;
        }

        return max(1, $hours) * 60;
    }

    private function resolveProviderKey(Supplier $supplier): ?string
    {
        $binding = app(PluginBindingResolver::class)->supplierBindingProjection($supplier);
        $providerKey = trim((string) ($binding['provider_key'] ?? ''));

        return $providerKey === '' ? null : $providerKey;
    }

    /**
     * 阈值状态机：跌破阈值时按冷却期告警，回升到阈值以上时清除告警标记，
     * 使得"再次跌破"能立刻重新提醒，而不必等冷却期结束。
     */
    private function handleThreshold(Supplier $supplier, SupplierBalance $record, ?string $previousBalance): bool
    {
        if (! $record->isBelowThreshold()) {
            if ($record->low_balance_notified_at !== null) {
                $record->forceFill(['low_balance_notified_at' => null])->save();
            }

            return false;
        }

        if (! $record->low_balance_alert_enabled) {
            return false;
        }

        $notifiedAt = $record->low_balance_notified_at;
        if ($notifiedAt !== null && $notifiedAt->diffInMinutes(now()) < $this->alertRepeatMinutes()) {
            return false;
        }

        $this->notifier->notifyLowBalance($supplier, $record, $previousBalance);
        $record->forceFill(['low_balance_notified_at' => now()])->save();

        return true;
    }
}
