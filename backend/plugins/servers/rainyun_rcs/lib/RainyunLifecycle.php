<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;

final class RainyunLifecycle
{
    public function __construct(private readonly RainyunClient $client, private readonly RainyunCatalog $catalog, private readonly RainyunConsole $console) {}

    public function provision(Order $order, Supplier $supplier, ?Service $service): array
    {
        $binding = app(PluginBindingResolver::class);
        $checkpoint = (array) data_get($order->config_pricing_snapshot, 'rainyun_provision', []);
        $existingId = $service ? (int) ($binding->upstreamServiceIdForService($service) ?? 0) : 0;
        $existingId = $existingId ?: (int) ($checkpoint['host_id'] ?? 0);
        if ($existingId > 0) {
            return $this->result($existingId, $this->client->detail($supplier, $existingId));
        }
        throw_if(($checkpoint['state'] ?? '') === 'submitted', new BusinessException('上次雨云开通结果未确认，请核对上游后绑定实例，禁止重复开通', 42200));
        $product = $order->product;
        throw_if($product === null, new BusinessException('订单商品不存在', 42200));
        $planId = (int) $binding->upstreamProductIdForProduct($product);
        $config = $this->orderConfig($order);
        $payload = $this->provisionPayload($config, $planId, (string) $order->billing_cycle);
        $plan = $this->catalog->plan($supplier, $planId);
        throw_if(! ($plan['is_selling'] ?? false) || (int) ($plan['available_stock'] ?? 0) === 0, new BusinessException('雨云套餐已停售或无库存', 42200));
        throw_if(! in_array($payload['os_id'], array_column($this->catalog->images($supplier, $plan), 'id'), true), new BusinessException('选定镜像不适用于雨云套餐', 42200));
        $this->checkpoint($order, ['state' => 'submitted', 'host_id' => 0]);
        try {
            $created = $this->client->request($supplier, 'POST', '/product/rcs/', $payload);
        } catch (RainyunApiException $exception) {
            if (! $exception->uncertain) {
                $this->checkpoint($order, ['state' => 'rejected', 'host_id' => 0]);
            }
            throw $exception;
        }
        $id = (int) ($created['ID'] ?? 0);
        throw_if($id <= 0, new BusinessException('雨云开通已提交但未返回实例 ID，请人工核对，勿重复下单', 42200));
        // 先持久化 ID；详情读取失败后重试只回查该实例，不再购买一次。
        $this->checkpoint($order, ['state' => 'created', 'host_id' => $id]);

        return $this->result($id, $this->client->detail($supplier, $id));
    }

    public function provisionPayload(array $config, int $planId, string $cycle): array
    {
        $payload = [
            'plan_id' => RainyunValues::integer($planId, '上游套餐 ID'),
            'os_id' => RainyunValues::integer($config['os_id'] ?? null, '系统镜像 ID'),
            'duration' => RainyunValues::months($cycle),
            'try' => RainyunValues::boolean($config['try'] ?? false, '试用'),
            'with_eip_num' => RainyunValues::integer($config['with_eip_num'] ?? 0, '独立 IP 数量', 0, 20),
            'with_eip_flags' => (string) ($config['with_eip_flags'] ?? ''),
            'with_coupon_id' => RainyunValues::integer($config['with_coupon_id'] ?? 0, '优惠券 ID', 0),
            'add_disk_size' => RainyunValues::integer($config['add_disk_size'] ?? 0, '附加系统盘容量', 0, 100000),
        ];
        if (! empty($config['disk_type'])) {
            $payload['disk_type'] = (string) $config['disk_type'];
        }

        return $payload;
    }

    public function renew(Supplier $supplier, int $id, string $cycle): array
    {
        $this->client->request($supplier, 'POST', $this->client->hostPath($id, 'renew/'), ['duration' => RainyunValues::months($cycle), 'with_coupon_id' => 0]);
        // 雨云直接扣账户余额，不返回独立账单号。平台已有续费 inflight 屏障。
        $detail = [];
        try {
            $detail = $this->console->host($this->client->detail($supplier, $id));
        } catch (RainyunApiException) {
            // 成功后的补充查询不能把已扣款续费误报为未执行。
        }

        return ['upstream_invoice_id' => 0, 'renew_completed_without_invoice' => true, 'payment_completed' => true, 'host_detail' => $detail];
    }

    private function result(int $id, array $detail): array
    {
        return ['upstream_invoice_id' => 0, 'upstream_host_id' => $id, 'upstream_host_ids' => [$id], 'host_detail' => $this->console->host($detail)];
    }

    private function checkpoint(Order $order, array $state): void
    {
        $snapshot = (array) ($order->config_pricing_snapshot ?? []);
        $snapshot['rainyun_provision'] = $state;
        $order->forceFill(['config_pricing_snapshot' => $snapshot])->save();
    }

    private function orderConfig(Order $order): array
    {
        $config = [];
        foreach ((array) ($order->product?->config_options ?? []) as $option) {
            if (! is_array($option) || empty($option['field'])) {
                continue;
            }
            foreach ($option['sub'] ?? $option['sub_items'] ?? [] as $sub) {
                if ($sub['is_default'] ?? $sub['default'] ?? false) {
                    $config[$option['field']] = $sub['value'] ?? $sub['id'] ?? null;
                    break;
                }
            }
        }

        return array_replace($config, (array) ($order->config_snapshot ?? []));
    }
}
