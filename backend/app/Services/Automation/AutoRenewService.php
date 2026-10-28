<?php

namespace App\Services\Automation;

use App\Constants\ServiceStatus;
use App\Models\AutomationLog;
use App\Models\Order;
use App\Models\Service;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoRenewService
{
    public function __construct(
        private ServiceRenewService $serviceRenewService,
        private PaymentService $paymentService,
    ) {}

    public function handle(): array
    {
        $aheadDays = (int) config('idc.auto_renew_days_before', 3);
        // 滚动窗口，而不是「只认 now+aheadDays 落在那一个自然日」。
        //
        // 原来的 whereBetween 把范围锁死在单日：如果当天最后一轮之后进程被 kill、
        // 或 cron 整体停摆了一天，这批服务就永久错过自动续费，直接进入到期暂停/终止
        // —— 用户余额充足也照停。窗口错过没有任何补偿路径。
        //
        // 现在取「到期时间落在 [今天, aheadDays 天后] 之间」的全部服务，
        // 靠 AutomationLog::recordOnce 的 ruleKey（含到期日）保证同一续费周期只扣一次，
        // 所以同一天重复运行不会被重复扣款。已过期的服务仍交给暂停/终止链路，不在这里补扣。
        $windowStart = now()->startOfDay()->toDateTimeString();
        $windowEnd = now()->addDays($aheadDays)->endOfDay()->toDateTimeString();

        $summary = [
            'matched' => 0,
            'paid' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
            'recovered' => 0,
            'blocked' => 0,
        ];

        Service::query()
            ->with(['user', 'product.supplier', 'order'])
            ->where('auto_renew', 1)
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::EXPIRED])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$windowStart, $windowEnd])
            ->orderBy('id')
            ->chunkById(100, function ($services) use (&$summary, $aheadDays) {
                foreach ($services as $service) {
                    $summary['matched']++;

                    $expiresAt = $service->expires_at instanceof Carbon
                        ? $service->expires_at
                        : Carbon::parse($service->expires_at);

                    $ruleKey = 'expiry:'.$expiresAt->format('Y-m-d').':auto_renew:'.$aheadDays;
                    if (! AutomationLog::recordOnce(
                        'auto_renew', 'charge', 'service', (int) $service->id, $ruleKey
                    )) {
                        $summary['skipped']++;

                        continue;
                    }

                    $lock = Cache::lock("lock:auto-renew:service:{$service->id}", 30);

                    $processed = $lock->get(function () use ($service, &$summary, $ruleKey, $expiresAt) {
                        if (! $service->user) {
                            $summary['skipped']++;

                            return true;
                        }

                        if (! $service->product) {
                            $summary['skipped']++;

                            return true;
                        }

                        try {
                            $traceId = 'auto_renew:service:'.$service->id.':'.now()->format('YmdHi');
                            $autoRenewContext = [
                                'auto_renew' => true,
                                'source' => 'auto_renew',
                                'operator' => 'auto_renew',
                                'actor_type' => 'system',
                                'actor_name' => '自动续费',
                                'trace_id' => $traceId,
                            ];
                            $preview = $this->serviceRenewService->previewForUser(
                                $service->user,
                                (int) $service->id,
                                (string) $service->billing_cycle,
                                0
                            );
                            $resolvedCycle = (string) ($preview['default_cycle'] ?? $service->billing_cycle);
                            $selectedCycle = collect($preview['cycles'] ?? [])->firstWhere('billing_cycle', $resolvedCycle);
                            $payableAmount = max((float) ($selectedCycle['amount'] ?? $preview['renew_price'] ?? 0), 0);
                            $service->user->refresh();

                            // 已有用户支付过且未履约完成的续费账单：等待既有账单履约，跳过本次扣款避免重复建单
                            $reusedPaidOrder = $this->serviceRenewService->findPaidUnfulfilledRenewOrder(
                                $service->user,
                                $service,
                                $resolvedCycle
                            );
                            if ($reusedPaidOrder instanceof Order) {
                                AutomationLog::markExecuted(
                                    'auto_renew',
                                    'charge',
                                    'service',
                                    (int) $service->id,
                                    $ruleKey,
                                    [
                                        'amount' => (float) ($reusedPaidOrder->invoice?->amount ?? $payableAmount),
                                        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                                        'reused_order_id' => (int) $reusedPaidOrder->id,
                                    ]
                                );
                                $summary['paid']++;

                                return true;
                            }

                            if ((float) $service->user->balance < $payableAmount || $payableAmount <= 0) {
                                $summary['pending']++;
                                // 余额不足不标记 executed，下次调度可以重试
                                AutomationLog::forgetRecord('auto_renew', 'charge', 'service', (int) $service->id, $ruleKey);

                                return true;
                            }

                            $order = $this->serviceRenewService->createRenewOrderForUser(
                                $service->user,
                                (int) $service->id,
                                $resolvedCycle,
                                0,
                                $autoRenewContext
                            );

                            if (! $order instanceof Order || ! $order->invoice) {
                                $summary['skipped']++;

                                return true;
                            }

                            $this->paymentService->payOrderByBalance(
                                $order->fresh(['invoice.product.supplier', 'invoice.service']) ?? $order,
                                $service->user,
                                $autoRenewContext
                            );

                            AutomationLog::markExecuted(
                                'auto_renew',
                                'charge',
                                'service',
                                (int) $service->id,
                                $ruleKey,
                                [
                                    'amount' => $payableAmount,
                                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                                ]
                            );
                            $summary['paid']++;

                            return true;
                        } catch (\Throwable $exception) {
                            $summary['failed']++;
                            AutomationLog::forgetRecord('auto_renew', 'charge', 'service', (int) $service->id, $ruleKey);

                            Log::error('[自动续费] 执行失败', [
                                'service_id' => $service->id,
                                'user_id' => $service->user_id,
                                'message' => $exception->getMessage(),
                                'exception' => $exception::class,
                            ]);

                            return true;
                        }
                    });

                    if (! $processed) {
                        $summary['skipped']++;
                    }
                }
            });

        return $summary;
    }
}
