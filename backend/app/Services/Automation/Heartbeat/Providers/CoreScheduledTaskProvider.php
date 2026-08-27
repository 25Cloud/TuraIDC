<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Providers;

use App\Models\Supplier;
use App\Services\Automation\AutoRenewService;
use App\Services\Automation\BillingAutomationService;
use App\Services\Automation\Heartbeat\CallbackScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\InvoiceCleanupAutomationService;
use App\Services\Automation\ServiceLifecycleAutomationService;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\Finance\CouponCampaignService;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Referral\ReferralService;
use App\Services\Supplier\SupplierBalanceService;
use App\Services\System\SettingService;
use App\Services\Ticket\TicketAutomationService;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderResolver;
use App\Support\AutomationScheduleExpression;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CoreScheduledTaskProvider implements ScheduledTaskProvider
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    public function tasks(): array
    {
        $automation = $this->automationConfig();

        return [
            $this->task(
                key: 'refresh-hosting-panel-auth',
                title: '接口认证刷新',
                category: '供应商接口',
                description: '定时刷新系统内置主机面板接口认证会话；插件供应商由各自插件任务负责。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => $this->refreshHostingPanelAuth(),
                timeout: 600,
                lockTtlSeconds: 660,
            ),
            $this->task(
                key: 'service-auto-renew',
                title: '服务自动续费',
                category: '服务续费',
                description: '扫描开启自动续费的服务，余额充足时自动创建续费账单并完成支付处理。',
                triggers: [ScheduleRule::everyTicks(4)],
                handler: function (): array {
                    $summary = app(AutoRenewService::class)->handle();
                    if (($summary['matched'] ?? 0) > 0) {
                        Log::info('[定时任务] 自动续费执行完成', $summary);
                    } else {
                        Log::debug('[定时任务] 自动续费执行完成（无匹配）', $summary);
                    }

                    return $summary;
                },
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'referral-release-rewards',
                title: '推荐奖励释放',
                category: '推荐奖励',
                description: '把已过冻结期的推荐奖励转入可提现余额，并记录推荐账户流水。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: function (): array {
                    $released = app(ReferralService::class)->releaseMaturedRewards();
                    if ($released > 0) {
                        Log::info('[定时任务] 推荐奖励释放执行完成', ['released' => $released]);
                    }

                    return ['released' => $released];
                },
                timeout: 600,
                lockTtlSeconds: 1200,
            ),
            $this->task(
                key: 'service-lifecycle-maintenance',
                title: '服务生命周期维护',
                category: '服务生命周期',
                description: '处理服务到期暂停、暂停通知和到期后自动取消。',
                triggers: [ScheduleRule::automation(
                    (string) ($automation['service_lifecycle_schedule_mode'] ?? AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES),
                    (string) ($automation['service_lifecycle_schedule_time'] ?? '00:00:00'),
                    AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
                    '00:00:00',
                )],
                handler: function (): array {
                    $summary = app(ServiceLifecycleAutomationService::class)->handle();
                    if (array_sum($summary) > 0) {
                        Log::info('[定时任务] 服务生命周期维护执行完成', $summary);
                    }

                    return $summary;
                },
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'service-status-sync',
                title: '用户产品状态同步',
                category: '服务状态',
                description: '定时拉取上游实例详情与运行状态，并同步回本地用户服务状态。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: function (): array {
                    // 插件任务 sync-zjmf-finance-inventory-and-services 已定向同步 ZJMF 服务状态时，
                    // 全量任务排除 ZJMF，避免同一服务每 15 分钟被上游拉取两轮；插件禁用时回退全量兜底。
                    $excludedProviderKeys = app(HeartbeatTaskRegistry::class)
                        ->supports('sync-zjmf-finance-inventory-and-services')
                        ? [ProviderKey::ZJMF_FINANCE_API]
                        : [];
                    $summary = app(ServiceStatusSyncService::class)->handle(100, 10, $excludedProviderKeys);
                    Log::info('[定时任务] 用户产品状态同步执行完成', $summary);

                    return $summary;
                },
                timeout: 1200,
                lockTtlSeconds: 1800,
            ),
            $this->task(
                key: 'billing-maintenance',
                title: '账单自动化维护',
                category: '账单提醒',
                description: '处理续费提醒、自动生成账单、账单到期提醒和逾期标记。',
                triggers: [ScheduleRule::automation(
                    (string) ($automation['billing_maintenance_schedule_mode'] ?? AutomationScheduleExpression::MODE_HOURLY),
                    (string) ($automation['billing_maintenance_schedule_time'] ?? '00:00:00'),
                    AutomationScheduleExpression::MODE_HOURLY,
                    '00:00:00',
                )],
                handler: function (): array {
                    $summary = app(BillingAutomationService::class)->handle();
                    Log::info('[定时任务] 账单自动化维护执行完成', $summary);

                    return $summary;
                },
                timeout: 1200,
                lockTtlSeconds: 1800,
            ),
            $this->task(
                key: 'supplier-product-stock-sync',
                title: '上游商品库存同步',
                category: '商品同步',
                description: '每个心跳槽（15 分钟）刷新一次已绑定上游商品的库存。批间等待 500ms，把并发打散成平缓请求，避免被上游风控拦截。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: function (): array {
                    $summary = app(ProductCatalogService::class)->syncUpstreamProductStocks();
                    Log::info('[定时任务] 上游商品库存同步执行完成', $summary);

                    return $summary;
                },
                // 全量刷需要 商品数/8 批 × (约 1s + 0.5s 等待)：473 个约 88 秒，
                // 留到 900 秒可覆盖到约 4800 个商品；再多就得考虑分批轮转。
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'supplier-balance-sync',
                title: '上游余额同步',
                category: '上游对接',
                description: '每个心跳槽（15 分钟）同步一次全部启用供应商的上游余额，余额低于阈值时邮件提醒管理员。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: function (): array {
                    $summary = app(SupplierBalanceService::class)->syncAll();
                    Log::info('[定时任务] 上游余额同步执行完成', $summary);

                    return $summary;
                },
                timeout: 600,
                lockTtlSeconds: 660,
            ),
            $this->task(
                key: 'product-upstream-config-sync',
                title: '上游产品配置同步',
                category: '商品同步',
                description: '每 24 小时拉取已绑定上游商品的配置项，并自动保存到本地商品配置，不同步商品定价。',
                triggers: [ScheduleRule::cron('0 0 * * *')],
                handler: function (): array {
                    $summary = app(ProductCatalogService::class)->syncUpstreamProductConfigOptions();
                    Log::info('[定时任务] 上游产品配置同步执行完成', $summary);

                    return $summary;
                },
                timeout: 3600,
                lockTtlSeconds: 3660,
            ),
            $this->task(
                key: 'log-archive',
                title: '日志归档',
                category: '系统维护',
                description: '每天归档超过保留期限的普通日志，并写入归档审计与执行报告。',
                triggers: [ScheduleRule::cron('0 2 * * *')],
                handler: fn (): array => $this->runArtisan('db:archive-logs', [
                    '--execute' => true,
                    '--json' => true,
                ]),
                timeout: 3600,
                lockTtlSeconds: 3660,
                manualTriggerable: false,
            ),
            $this->task(
                key: 'coupon-campaign-dispatch',
                title: '优惠券活动发放',
                category: '营销活动',
                description: '按活动配置的星期与时间自动生成一批公开优惠券，例如每周五 18:00 发放周五特惠。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: function (): array {
                    $summary = app(CouponCampaignService::class)->dispatchDueCampaigns();
                    if (($summary['triggered'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
                        Log::info('[定时任务] 优惠券活动自动发放执行完成', $summary);
                    }

                    return $summary;
                },
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'ticket-auto-close',
                title: '工单自动关闭',
                category: '工单管理',
                description: '关闭超过阈值且长期无客户回复的工单。',
                triggers: [ScheduleRule::automation(
                    (string) ($automation['ticket_auto_close_schedule_mode'] ?? AutomationScheduleExpression::MODE_HOURLY),
                    (string) ($automation['ticket_auto_close_schedule_time'] ?? '00:00:00'),
                    AutomationScheduleExpression::MODE_HOURLY,
                    '00:00:00',
                )],
                handler: function (): array {
                    $summary = app(TicketAutomationService::class)->handle();
                    Log::info('[定时任务] 工单自动关闭执行完成', $summary);

                    return $summary;
                },
                timeout: 900,
                lockTtlSeconds: 1200,
            ),
            $this->task(
                key: 'order-cleanup',
                title: '账单与充值清理',
                category: '账单清理',
                description: '自动取消超过 5 分钟未付款的订单、账单和充值单。',
                triggers: [ScheduleRule::automation(
                    (string) ($automation['order_cleanup_schedule_mode'] ?? AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES),
                    (string) ($automation['order_cleanup_schedule_time'] ?? '00:00:00'),
                    AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
                    '00:00:00',
                )],
                handler: function (): array {
                    $summary = app(InvoiceCleanupAutomationService::class)->handle();
                    if (array_sum($summary) > 0) {
                        Log::info('[定时任务] 账单与充值清理执行完成', $summary);
                    }

                    return $summary;
                },
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'reconcile-account-balance',
                title: '账户余额在线对账',
                category: '财务对账',
                description: '定时执行账户余额在线对账，及时发现余额投影异常。',
                triggers: [ScheduleRule::everyTicks(4)],
                handler: function (): array {
                    $summary = $this->runArtisan('reconcile:account-balance', [], false);
                    if (($summary['exit_code'] ?? 0) !== 0) {
                        Log::warning('[定时任务] 账户余额对账发现差异，已记录告警且不进行队列重试', $summary);
                        $summary['status'] = 'warning';
                    }

                    return $summary;
                },
                timeout: 1200,
                lockTtlSeconds: 1800,
            ),
            $this->task(
                key: 'audit-ledger-consistency',
                title: '资金流水一致性审计',
                category: '财务对账',
                description: '每日审计账户流水来源完整性、余额与流水一致性、充值桥接覆盖，账实不符即告警。',
                triggers: [ScheduleRule::cron('0 3 * * *')],
                handler: function (): array {
                    $summary = $this->runArtisan('finance:audit-ledger-consistency', ['--strict' => true], false);
                    if (($summary['exit_code'] ?? 0) !== 0) {
                        Log::warning('[定时任务] 资金流水一致性审计发现差异，已记录告警且不进行队列重试', $summary);
                        $summary['status'] = 'warning';
                    }

                    return $summary;
                },
                timeout: 1200,
                lockTtlSeconds: 1800,
                manualTriggerable: false,
            ),
            $this->task(
                key: 'provision-retry-failed',
                title: '上游开通孤儿单补偿告警',
                category: '上游开通',
                description: '默认 dry-run 扫描上游开通失败孤儿单并写日志告警。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => $this->runArtisan('provision:retry-failed'),
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'site-cache-warmup',
                title: '首页缓存预热',
                category: '站点缓存',
                description: '刷新首页与产品目录缓存，保持站点缓存热度。',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => $this->runArtisan('app:warmup-site-cache'),
                timeout: 900,
                lockTtlSeconds: 960,
            ),
            $this->task(
                key: 'compensate-recharge-invoices',
                title: '充值账单补偿',
                category: '财务补偿',
                description: '扫描成功但缺失账单关联的充值支付记录，补建充值账单。',
                triggers: [ScheduleRule::everyTicks(4)],
                handler: fn (): array => $this->runArtisan('payment:compensate-recharge-invoices', ['--limit' => 200]),
                timeout: 1200,
                lockTtlSeconds: 1800,
            ),
        ];
    }

    /**
     * @param  list<TriggerRule>  $triggers
     * @param  Closure(TaskContext):mixed  $handler
     */
    private function task(
        string $key,
        string $title,
        string $category,
        string $description,
        array $triggers,
        Closure $handler,
        ?string $queue = null,
        int $timeout = 900,
        int $lockTtlSeconds = 1200,
        bool $manualTriggerable = true,
    ): ScheduledTask {
        return new CallbackScheduledTask(
            key: $key,
            title: $title,
            description: $description,
            category: $category,
            triggers: $triggers,
            handler: $handler,
            queue: $queue,
            timeout: $timeout,
            lockTtlSeconds: $lockTtlSeconds,
            manualTriggerable: $manualTriggerable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function automationConfig(): array
    {
        try {
            config([
                'idc.schedule_runtime.automation_config' => [
                    'status' => 'loaded',
                    'fallback_reason' => '',
                ],
            ]);

            return $this->settingService->getAutomationConfig();
        } catch (Throwable $exception) {
            config([
                'idc.schedule_runtime.automation_config' => [
                    'status' => 'fallback_default',
                    'fallback_reason' => trim((string) $exception->getMessage()),
                ],
            ]);

            Log::warning('[定时任务] 自动化配置读取失败，已回退到默认调度配置', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return SettingService::defaultAutomationConfig();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshHostingPanelAuth(): array
    {
        $providerResolver = app(ProviderResolver::class);
        $summary = [
            'matched' => 0,
            'refreshed' => 0,
            'failed' => 0,
        ];

        Supplier::query()
            ->enabled()
            ->orderBy('id')
            ->get()
            ->each(function (Supplier $supplier) use ($providerResolver, &$summary): void {
                $provider = $providerResolver->resolveForSupplier($supplier);
                $providerKey = trim((string) ($provider->key() ?? $provider->rawKey() ?? ''));
                if ($providerKey !== ProviderKey::HOSTING_PANEL_API) {
                    return;
                }

                if (! $provider->supports(ProvidesScheduledAuthRefresh::class)) {
                    return;
                }

                $summary['matched']++;

                try {
                    $provider->require(ProvidesScheduledAuthRefresh::class, '当前供应商不支持认证刷新')
                        ->refreshJwt($supplier);
                    $summary['refreshed']++;
                } catch (Throwable $exception) {
                    $summary['failed']++;

                    Log::error('[定时任务] JWT刷新失败', [
                        'supplier_id' => $supplier->id,
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            });

        Log::info('[定时任务] 接口认证刷新执行完成', $summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function runArtisan(string $command, array $parameters = [], bool $throwOnFailure = true): array
    {
        $exitCode = Artisan::call($command, $parameters);
        $output = trim(Artisan::output());

        $summary = [
            'command' => $command,
            'exit_code' => $exitCode,
        ];

        if ($parameters !== []) {
            $summary['parameters'] = $parameters;
        }

        if ($output !== '') {
            $summary['output'] = mb_substr($output, 0, 2000);
        }

        if ($exitCode !== 0 && $throwOnFailure) {
            $message = "Artisan command [{$command}] exited with code {$exitCode}.";
            if ($output !== '') {
                $message .= ' Output: '.mb_substr($output, 0, 1000);
            }

            throw new RuntimeException($message);
        }

        return $summary;
    }
}
