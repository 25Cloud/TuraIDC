<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 上游流量包通道：魔方财务把我们当上游时，用户在魔方站内买流量包。
 *
 * 魔方财务的调用顺序（3.7.6 起固定，3.8.0 只多了一个状态查询）：
 *   1. 主机页读 /host/header 的 data.dcim.flowpacket 拿档位列表（id/name/capacity/price/leave）；
 *   2. 用户在魔方站内下单，魔方自己先收钱，付完后回调 /dcim/buy_flow_packet（id=dcimid、fid=档位 id）；
 *   3. 我们把账单建好回 data.invoiceid，魔方立刻用 /apply_credit 拿上游余额付这张账单。
 *
 * 因此这里只做两件事：把本地流量包目录翻译成魔方的档位格式、按档位建账单。
 * 真正的上游采购（向我们的供应商买流量包）由账单支付后的
 * ServiceTrafficPackageService::processPaidTrafficPackageOrder 完成，
 * 与站内用户买流量包是同一条链路，不重复实现。
 */
class FlowPacketService
{
    /** 幂等键保留时长：魔方重试窗口远小于一天，够覆盖重复提交与超时重试。 */
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(
        private readonly ServiceTrafficPackageService $trafficPackages,
    ) {}

    /**
     * 可售档位（调用方已完成服务归属校验）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function packagesForService(Service $service, int $currentLimit = 0): array
    {
        try {
            return $this->trafficPackages->sellablePackagesForService($service, $currentLimit);
        } catch (\Throwable $exception) {
            // 主机页是下游的打开首屏，流量包列表算不出来也不能让整页 500
            Log::warning('[zjmf-upstream] 流量包档位读取失败', [
                'service_id' => (int) $service->id,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 本计费周期内已购流量包记录，供下游渲染「流量包使用记录」。
     *
     * @return array<int, array<string, mixed>>
     */
    public function usageForService(Service $service): array
    {
        $orders = Order::query()
            ->where('service_id', (int) $service->id)
            ->where('type', OrderType::UPGRADE)
            ->where('status', OrderStatus::COMPLETED)
            ->where('paid_at', '>=', now()->startOfMonth())
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get();

        return $orders
            ->filter(fn (Order $order): bool => (string) data_get($order->config_pricing_snapshot ?? [], 'meta.kind', '') === 'traffic_package')
            ->map(function (Order $order): array {
                $meta = (array) data_get($order->config_pricing_snapshot ?? [], 'meta', []);
                $capacity = (int) ($meta['target_snapshot'] ?? 0);
                $label = trim((string) ($meta['target_label'] ?? ''));
                if ($label === '') {
                    $label = $capacity > 0 ? $capacity.'G' : '流量包';
                }

                $paidAt = $order->getAttribute('paid_at');

                return [
                    'name' => $label.' 流量包',
                    'capacity' => $capacity,
                    'price' => number_format((float) ($order->amount ?? 0), 2, '.', ''),
                    // paid_at 是 datetime cast；魔方读的是 unix 时间戳
                    'pay_time' => $paidAt instanceof \DateTimeInterface ? $paidAt->getTimestamp() : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * /dcim/buy_flow_packet：按档位建待支付账单，返回 data.invoiceid。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function purchase(User $user, array $data): array
    {
        $serviceId = (int) ($data['id'] ?? $data['host_id'] ?? $data['hostid'] ?? 0);
        if ($serviceId <= 0) {
            return ['status' => 400, 'msg' => '缺少主机 ID'];
        }

        $service = Service::query()
            ->with('product')
            ->where('user_id', (int) $user->id)
            ->find($serviceId);

        if (! $service instanceof Service) {
            return ['status' => 400, 'msg' => '服务不存在'];
        }

        // 魔方 3.7.6 传 fid，3.8.0 同时带 flow_packet_id，部分是 selected_capacity 走数量模式
        $tier = (int) ($data['fid'] ?? 0);
        if ($tier <= 0) {
            $tier = (int) ($data['flow_packet_id'] ?? 0);
        }
        if ($tier <= 0) {
            $tier = (int) ($data['selected_capacity'] ?? 0);
        }
        if ($tier <= 0) {
            return ['status' => 400, 'msg' => '缺少流量包档位'];
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        $cacheKey = $this->idempotencyCacheKey($user, $idempotencyKey);
        if ($cacheKey !== null) {
            $reused = $this->reuseInvoice($user, (int) Cache::get($cacheKey, 0));
            if ($reused instanceof Invoice) {
                return $this->invoiceResponse($reused, $serviceId);
            }
        }

        $matched = null;
        foreach ($this->packagesForService($service) as $package) {
            if ((int) ($package['id'] ?? 0) === $tier) {
                $matched = $package;
                break;
            }
        }

        if (! is_array($matched)) {
            return ['status' => 400, 'msg' => '该流量包档位不在售，或已低于实例当前流量'];
        }

        try {
            $invoice = $this->trafficPackages->createInvoiceForUser($user, $serviceId, ['target_value' => $tier], [
                'trace_id' => 'zjmf:flow_packet:'.(int) $user->id.':'.($idempotencyKey !== '' ? $idempotencyKey : $tier.'@'.$serviceId),
                'actor_type' => 'system',
                'actor_user_id' => 0,
                'actor_name' => '魔方财务API',
            ]);
        } catch (BusinessException $exception) {
            return ['status' => 400, 'msg' => $exception->getMessage()];
        }

        if ($cacheKey !== null) {
            Cache::put($cacheKey, (int) $invoice->id, now()->addHours(self::IDEMPOTENCY_TTL_HOURS));
        }

        Log::info('[zjmf-upstream] 流量包账单已创建', [
            'user_id' => (int) $user->id,
            'service_id' => $serviceId,
            'tier' => $tier,
            'invoice_id' => (int) $invoice->id,
            'amount' => (string) $invoice->amount,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->invoiceResponse($invoice, $serviceId);
    }

    /**
     * /dcim/flow_packet_order_status（3.8.0 新增）：返回 Paid / Pending / Unpaid。
     *
     * 账单付完只代表钱到了，向上游真正买流量包是支付后的队列任务，
     * 所以这里按「账单状态 + 订单状态」两段判定，不能把已付直接当成已完成。
     *
     * @return array<string, mixed>
     */
    public function orderStatus(User $user, int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return ['status' => 400, 'msg' => '缺少账单 ID'];
        }

        $invoice = Invoice::query()
            ->with('order')
            ->where('user_id', (int) $user->id)
            ->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return ['status' => 400, 'msg' => '账单不存在'];
        }

        $meta = (array) data_get($invoice->config_pricing_snapshot ?? [], 'meta', []);
        if ((string) ($meta['kind'] ?? '') !== 'traffic_package') {
            // 魔方收到 409 会按「结果未知」处理，不会误判成失败
            return ['status' => 409, 'msg' => '该账单不是流量包订单', 'code' => 'Unknown', 'unknown' => true];
        }

        $order = $invoice->order;
        $paid = (int) $invoice->status === InvoiceStatus::PAID;
        $completed = $order instanceof Order && (int) $order->status === OrderStatus::COMPLETED;

        $code = match (true) {
            ! $paid => 'Unpaid',
            $completed => 'Paid',
            default => 'Pending',
        };

        $payload = [
            'invoiceid' => (int) $invoice->id,
            'order_id' => $order instanceof Order ? (int) $order->id : 0,
            'hostid' => [(int) $invoice->service_id],
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'target_label' => (string) ($meta['target_label'] ?? ''),
        ];

        if ($code === 'Pending') {
            $payload['error'] = $this->pendingUpgradeError((int) $invoice->service_id);
        }

        return [
            'status' => 200,
            'msg' => '请求成功',
            'code' => $code,
            'data' => $payload,
        ];
    }

    /**
     * 已付但还没执行完时，把最近一次失败原因带给下游，便于下游面板显示真实原因。
     */
    private function pendingUpgradeError(int $serviceId): string
    {
        if ($serviceId <= 0) {
            return '';
        }

        $provisionData = Service::query()->whereKey($serviceId)->value('provision_data');
        if (is_string($provisionData)) {
            $provisionData = json_decode($provisionData, true);
        }

        return is_array($provisionData) ? trim((string) ($provisionData['upgrade_error'] ?? '')) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceResponse(Invoice $invoice, int $serviceId): array
    {
        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'invoiceid' => (int) $invoice->id,
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'price' => number_format((float) ($invoice->amount ?? 0), 2, '.', ''),
                'currency' => 'CNY',
                'hostid' => $serviceId > 0 ? [$serviceId] : [],
            ],
        ];
    }

    private function reuseInvoice(User $user, int $invoiceId): ?Invoice
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $invoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->find($invoiceId);

        return $invoice instanceof Invoice ? $invoice : null;
    }

    private function idempotencyCacheKey(User $user, string $idempotencyKey): ?string
    {
        if ($idempotencyKey === '') {
            return null;
        }

        return 'zjmf:flow_packet:idem:'.(int) $user->id.':'.sha1($idempotencyKey);
    }
}
