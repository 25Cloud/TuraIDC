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
        // 自动续费在到期前 $aheadDays 天执行，窗口为当天
        $targetDate = now()->addDays($aheadDays)->toDateString();

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
            ->whereBetween('expires_at', [
                $targetDate.' 00:00:00',
                $targetDate.' 23:59:59',
            ])
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
