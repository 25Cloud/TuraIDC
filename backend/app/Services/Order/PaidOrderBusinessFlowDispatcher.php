<?php

namespace App\Services\Order;

use App\Jobs\ProcessPaidOrderFulfillmentJob;
use App\Jobs\ProcessPaidOrderReferralRewardJob;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Predis\Client;

class PaidOrderBusinessFlowDispatcher
{
    /** 支付后同步履约的订单类型：立即开通，失败回退队列兜底 */
    private const SYNCHRONOUS_FULFILLMENT_TYPES = ['new', 'renew'];

    private ?bool $databaseQueueReady = null;

    public function dispatchPaidInvoice(Invoice $invoice, ?string $traceId = null): void
    {
        $orderId = (int) ($invoice->order?->id ?? 0);

        if ($orderId <= 0) {
            return;
        }

        $shouldDispatchReferralReward = $this->shouldDispatchReferralReward($invoice);

        if ($this->shouldSynchronouslyFulfill($invoice)) {
            $this->dispatchFulfillmentSynchronously($orderId, $invoice, $traceId, $shouldDispatchReferralReward);

            return;
        }

        if (app()->runningInConsole()) {
            if ($this->shouldUseQueue()) {
                if ($shouldDispatchReferralReward) {
                    ProcessPaidOrderReferralRewardJob::dispatch($orderId, $traceId);
                }

                ProcessPaidOrderFulfillmentJob::dispatch($orderId);

                return;
            }

            $this->logFallbackDispatch($orderId);
            if ($shouldDispatchReferralReward) {
                ProcessPaidOrderReferralRewardJob::dispatchSync($orderId, $traceId);
            }

            ProcessPaidOrderFulfillmentJob::dispatchSync($orderId);

            return;
        }

        if ($this->shouldUseQueue()) {
            if ($shouldDispatchReferralReward) {
                ProcessPaidOrderReferralRewardJob::dispatch($orderId, $traceId);
            }

            ProcessPaidOrderFulfillmentJob::dispatch($orderId);

            return;
        }

        $this->logFallbackDispatch($orderId);

        if ($shouldDispatchReferralReward) {
            ProcessPaidOrderReferralRewardJob::dispatchAfterResponse($orderId, $traceId);
        }

        ProcessPaidOrderFulfillmentJob::dispatchAfterResponse($orderId);
    }

    private function dispatchFulfillmentSynchronously(
        int $orderId,
        Invoice $invoice,
        ?string $traceId,
        bool $shouldDispatchReferralReward,
    ): void {
        if ($shouldDispatchReferralReward) {
            ProcessPaidOrderReferralRewardJob::dispatch($orderId, $traceId);
        }

        try {
            ProcessPaidOrderFulfillmentJob::dispatchSync($orderId);

            return;
        } catch (\Throwable $exception) {
            Log::warning('[支付后业务流] 同步履约失败，回退队列等待重试', [
                'order_id' => $orderId,
                'invoice_id' => (int) $invoice->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        $this->dispatchFulfillmentViaQueueOrFallback($orderId);
    }

    private function dispatchFulfillmentViaQueueOrFallback(int $orderId): void
    {
        if ($this->shouldUseQueue()) {
            ProcessPaidOrderFulfillmentJob::dispatch($orderId);

            return;
        }

        $this->logFallbackDispatch($orderId);

        if (app()->runningInConsole()) {
            ProcessPaidOrderFulfillmentJob::dispatchSync($orderId);

            return;
        }

        ProcessPaidOrderFulfillmentJob::dispatchAfterResponse($orderId);
    }

    private function shouldDispatchReferralReward(Invoice $invoice): bool
    {
        return (string) ($invoice->order?->type ?? $invoice->type ?? '') === 'new';
    }

    private function shouldSynchronouslyFulfill(Invoice $invoice): bool
    {
        $order = $invoice->order;

        $type = $order instanceof Order
            ? (string) $order->getAttribute('type')
            : (string) $invoice->getAttribute('type');

        return in_array($type, self::SYNCHRONOUS_FULFILLMENT_TYPES, true);
    }

    private function shouldUseQueue(): bool
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === '' || $driver === 'sync') {
            return false;
        }

        if ($driver === 'database') {
            return $this->databaseQueueIsReady();
        }

        if ($driver === 'redis') {
            return extension_loaded('redis') || class_exists(Client::class);
        }

        return true;
    }

    private function databaseQueueIsReady(): bool
    {
        if ($this->databaseQueueReady !== null) {
            return $this->databaseQueueReady;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');

        try {
            $this->databaseQueueReady = $table !== '' && Schema::hasTable($table);
        } catch (\Throwable $exception) {
            Log::warning('[支付后业务流] 检查队列表失败，回退为 afterResponse', [
                'table' => $table,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $this->databaseQueueReady = false;
        }

        return $this->databaseQueueReady;
    }

    private function logFallbackDispatch(int $orderId): void
    {
        Log::info('[支付后业务流] 队列未就绪，回退为 afterResponse/同步执行', [
            'order_id' => $orderId,
            'queue_driver' => (string) config('queue.default', 'sync'),
            'running_in_console' => app()->runningInConsole(),
        ]);
    }
}
