<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Finance\InvoiceService;
use App\Services\Integrations\Plugins\PluginConsolePurchaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RainyunPurchases
{
    public function __construct(private readonly RainyunClient $client) {}

    public function submit(Service $service, Supplier $supplier, int $id, array $input): array
    {
        $binding = RainyunBinding::require($service, $supplier, $id);
        $detail = $this->client->detail($supplier, $id);
        $quote = $this->quote($detail, (array) ($supplier->provider_config ?? []), $input, time());
        $quote['fingerprint'] = $this->fingerprint($detail, $quote['kind']);
        if ($quote['monthly_delta'] !== 0) {
            RainyunBilling::renewalFields($service, $quote['monthly_delta']);
        }
        if (($input['func'] ?? '') === 'quote') {
            $token = bin2hex(random_bytes(24));
            Cache::put('rainyun:quote:'.$token, ['service_id' => (int) $service->id, 'binding_id' => (int) $binding->id,
                'supplier_id' => (int) $supplier->id, 'plugin_id' => (int) $binding->plugin_id, 'host_id' => $id, 'quote' => $quote], 600);

            return ['status' => 200, 'data' => ['amount' => RainyunValues::money(max(0, $quote['cents'])),
                'quote_token' => $token,
                'refund_amount' => RainyunValues::money(max(0, -$quote['cents'])),
                'monthly_change' => ($quote['monthly_delta'] < 0 ? '-' : '').RainyunValues::money(abs($quote['monthly_delta']))], 'msg' => '报价已生成'];
        }

        $token = (string) ($input['quote_token'] ?? '');
        throw_if(preg_match('/^[a-f0-9]{48}$/D', $token) !== 1, new BusinessException('请先获取并确认报价', 42200));
        $cached = Cache::get('rainyun:quote:'.$token);
        throw_if(! is_array($cached) || ($cached['service_id'] ?? 0) !== (int) $service->id
            || ($cached['binding_id'] ?? 0) !== (int) $binding->id || ($cached['plugin_id'] ?? 0) !== (int) $binding->plugin_id
            || ($cached['supplier_id'] ?? 0) !== (int) $supplier->id || ($cached['host_id'] ?? 0) !== $id
            || ($cached['quote']['kind'] ?? '') !== $quote['kind'] || ($cached['quote']['body'] ?? []) !== $quote['body']
            || ($cached['quote']['fingerprint'] ?? '') !== $quote['fingerprint'], new BusinessException('报价已过期或实例配置已变化，请重新确认', 42200));
        $quote = $cached['quote'];

        $invoice = Cache::lock('rainyun:checkout:'.$service->id, 20)->block(3, function () use ($service, $supplier, $id, $quote, $binding, $token): Invoice {
            return DB::transaction(function () use ($service, $supplier, $id, $quote, $binding, $token): Invoice {
                $service = Service::query()->lockForUpdate()->findOrFail($service->id);
                // 报价也是建单幂等键；付款完成后重复提交仍返回原账单，不能再次购买或退款。
                $existing = Invoice::query()->where('service_id', $service->id)->where('type', 'upgrade')
                    ->where('config_pricing_snapshot->meta->quote_token', $token)->first();
                if ($existing !== null) {
                    throw_if(! in_array((int) $existing->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE, InvoiceStatus::PAID], true),
                        new BusinessException('该报价对应的账单已关闭，请重新获取报价', 42200));

                    return $existing;
                }
                $unresolved = Order::query()->where('service_id', $service->id)->where('type', 'upgrade')
                    ->whereIn('status', [OrderStatus::PAID, OrderStatus::PROCESSING])->exists();
                throw_if($unresolved, new BusinessException('该实例仍有待完成或待核对的升级订单，请先处理', 42200));
                $pending = Invoice::query()->where('service_id', $service->id)->where('type', 'upgrade')
                    ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE])->first();
                throw_if($pending !== null, new BusinessException('请先支付或取消当前实例的待支付升级账单', 42200));
                $amount = RainyunValues::money(max(0, $quote['cents']));
                $pricing = ['meta' => ['kind' => 'plugin_purchase', 'provider' => 'rainyun_rcs',
                    'plugin_id' => (int) $binding->plugin_id, 'binding_id' => (int) $binding->id,
                    'supplier_id' => (int) $supplier->id, 'host_id' => $id, 'quote' => $quote, 'quote_token' => $token, 'state' => 'ready']];
                $order = Order::query()->create(['order_no' => Order::generateOrderNo(),
                    'projection_type' => Order::PROJECTION_TYPE_PROVISIONING, 'user_id' => (int) $service->user_id,
                    'product_id' => (int) $service->product_id, 'service_id' => (int) $service->id,
                    'product_spec_snapshot' => $quote['label'], 'product_type_snapshot' => $service->product?->product_type,
                    'type' => 'upgrade', 'amount' => $amount, 'discount' => '0.00', 'paid_amount' => '0.00',
                    'billing_cycle' => 'one_time', 'quantity' => 1, 'status' => OrderStatus::PENDING,
                    'config_snapshot' => [], 'config_pricing_snapshot' => $pricing]);
                $invoice = Invoice::query()->create(['invoice_no' => Invoice::generateInvoiceNoFromOrderNo($order->order_no),
                    'order_id' => $order->id, 'user_id' => $service->user_id, 'product_id' => $service->product_id,
                    'service_id' => $service->id, 'product_spec_snapshot' => $quote['label'],
                    'product_type_snapshot' => $service->product?->product_type, 'type' => 'upgrade', 'amount' => $amount,
                    'discount' => '0.00', 'paid_amount' => '0.00', 'billing_cycle' => 'one_time', 'quantity' => 1,
                    'status' => InvoiceStatus::UNPAID, 'due_date' => now()->addDay(), 'config_snapshot' => [], 'config_pricing_snapshot' => $pricing]);
                if ($quote['cents'] <= 0) {
                    // 无应付金额也保留零元账单，返还必须等待上游操作成功。
                    $invoice->forceFill(['status' => InvoiceStatus::PAID, 'paid_at' => now()])->save();
                    $order->forceFill(['status' => OrderStatus::PAID, 'paid_at' => now()])->save();
                }

                return app(InvoiceService::class)->syncProjection($invoice);
            });
        });
        if ((int) $invoice->status === InvoiceStatus::PAID) {
            app(PluginConsolePurchaseService::class)->fulfill($invoice->order);
        }

        return ['status' => 200, 'msg' => (int) $invoice->status === InvoiceStatus::PAID ? '调整已完成' : '账单已生成，请前往账单记录支付',
            'data' => ['invoice_id' => (int) $invoice->id, 'amount' => (string) $invoice->amount]];
    }

    public function quote(array $detail, array $prices, array $input, int $now): array
    {
        $kind = (string) ($input['kind'] ?? '');
        $host = $detail['Data'];
        throw_if(($host['Try'] ?? false) || (int) ($host['ExpDate'] ?? 0) <= $now, new BusinessException('试用或已到期实例不能购买附加资源', 42200));
        $monthlyDelta = 0;
        if ($kind === 'traffic' || $kind === 'gpu_time') {
            $gpu = ! empty($host['Plan']['dgpu_dev_type']);
            throw_if(($kind === 'gpu_time') !== $gpu, new BusinessException('购买类型与当前实例不匹配', 42200));
            $quantity = RainyunValues::integer($input['quantity'] ?? null, $gpu ? '小时数' : '流量 GB', 1, 100000);
            $unit = RainyunValues::cents($prices[$gpu ? 'gpu_hour_price' : 'traffic_price'] ?? '', '附加资源销售单价');
            throw_if($unit <= 0, new BusinessException('附加资源销售单价必须大于零', 42200));
            $body = [$gpu ? 'hour' : 'traffic_in_gb' => $quantity];
            $cents = $unit * $quantity;
            $label = $gpu ? '雨云 GPU 时长 '.$quantity.' 小时' : '雨云流量包 '.$quantity.' GB';
        } elseif ($kind === 'plan') {
            $target = RainyunValues::integer($input['plan_id'] ?? null, '目标套餐 ID');
            throw_if(! in_array($target, array_map(fn ($p) => (int) $p['id'], $detail['UpgradeablePlans'] ?? []), true),
                new BusinessException('当前实例不支持升级至该套餐', 42200));
            $map = $prices['plan_monthly_prices'] ?? [];
            if (is_string($map)) {
                $map = json_decode($map, true);
            }
            throw_if(! is_array($map), new BusinessException('请配置套餐月销售价格表', 42200));
            $oldPrice = RainyunValues::cents($map[$host['PlanID']] ?? '', '当前套餐月销售价格');
            $newPrice = RainyunValues::cents($map[$target] ?? '', '目标套餐月销售价格');
            $monthlyDelta = $newPrice - $oldPrice;
            throw_if($monthlyDelta <= 0, new BusinessException('该入口仅支持按已配置销售价升级套餐', 42200));
            $body = ['dest_plan' => $target, 'with_coupon_id' => 0];
            $cents = (int) round($monthlyDelta * ((int) $host['ExpDate'] - $now) / (31 * 86400));
            $label = '雨云套餐升级 '.$target;
        } elseif ($kind === 'ip') {
            $quantity = RainyunValues::integer($input['quantity'] ?? null, '附加 IP 数量', 1, 20);
            $flags = (string) ($input['flags'] ?? '');
            $available = (array) ($host['Plan']['ip_prices'] ?? []);
            throw_if(! array_key_exists($flags, $available), new BusinessException('该实例不支持所选 IP 线路', 42200));
            $map = $prices['ip_monthly_prices'] ?? [];
            if (is_string($map)) {
                $map = json_decode($map, true);
            }
            throw_if(! is_array($map), new BusinessException('请配置 IP 月销售价格表', 42200));
            $unit = RainyunValues::cents($map[$flags] ?? '', 'IP 月销售价格');
            throw_if($unit <= 0, new BusinessException('IP 销售价格必须大于零', 42200));
            $monthlyDelta = $unit * $quantity;
            $body = ['with_ip_num' => $quantity, 'with_flags' => $flags, 'with_ip_type' => 'IPv4'];
            $cents = (int) round($monthlyDelta * ((int) $host['ExpDate'] - $now) / (31 * 86400));
            $label = '雨云附加 IPv4 '.$quantity.' 个';
        } elseif ($kind === 'disk') {
            $operation = (string) ($input['operation'] ?? '');
            $disks = (array) ($detail['EDiskList'] ?? []);
            if ($operation === 'create') {
                $type = (string) ($input['disk_type'] ?? '');
                throw_if(! in_array($type, $detail['AvailableDiskType'] ?? [], true), new BusinessException('该实例不支持所选云盘类型', 42200));
                $size = RainyunValues::integer($input['size'] ?? null, '云盘容量', 1, 100000);
                $backup = RainyunValues::boolean($input['backup'] ?? false, '云盘备份');
                $monthlyDelta = $this->diskUnit($type, $backup, $prices) * $size;
                $body = ['actions' => [['type' => 'create', 'action' => ['size_in_gb' => $size, 'disk_type' => $type, 'backup' => $backup, 'tag' => '']]]];
            } else {
                throw_if(! in_array($operation, ['expand', 'delete'], true), new BusinessException('云盘操作无效', 42200));
                $diskId = RainyunValues::integer($input['disk_id'] ?? null, '云盘 ID');
                $disk = collect($disks)->first(fn ($d) => (int) ($d['ID'] ?? 0) === $diskId);
                throw_if(! is_array($disk), new BusinessException('云盘不属于当前实例', 42200));
                $base = (int) ($disk['Slot'] ?? -1) === 0 ? (int) ($host['Plan']['disk_size'][$disk['DiskType']] ?? 30) : 0;
                $oldSize = (int) $disk['Size'];
                $oldPrice = max(0, $oldSize - $base) * $this->diskUnit($disk['DiskType'], (bool) $disk['Backup'], $prices);
                if ($operation === 'delete') {
                    throw_if((int) ($disk['Slot'] ?? 0) === 0, new BusinessException('不能删除系统盘', 42200));
                    $monthlyDelta = -$oldPrice;
                    $body = ['actions' => [['type' => 'delete', 'action' => ['edisk_id' => $diskId]]]];
                } else {
                    $size = RainyunValues::integer($input['size'] ?? null, '目标容量', $oldSize, 100000);
                    $backup = RainyunValues::boolean($input['backup'] ?? false, '云盘备份');
                    throw_if($size === $oldSize && $backup === (bool) $disk['Backup'], new BusinessException('云盘配置没有变化', 42200));
                    $monthlyDelta = max(0, $size - $base) * $this->diskUnit($disk['DiskType'], $backup, $prices) - $oldPrice;
                    $body = ['actions' => [['type' => 'expand', 'action' => ['edisk_id' => $diskId, 'size_in_gb' => $size - $oldSize, 'backup' => $backup]]]];
                }
            }
            // 保留原插件按 31 天折算剩余服务期的口径，计算始终使用整数分。
            $seconds = max(0, (int) $host['ExpDate'] - $now);
            $cents = (int) round($monthlyDelta * $seconds / (31 * 86400));
            $label = '雨云云盘调整';
        } else {
            throw new BusinessException('不支持的雨云购买类型', 42200);
        }

        return ['kind' => $kind, 'body' => $body, 'cents' => $cents, 'monthly_delta' => $monthlyDelta, 'label' => $label];
    }

    public function fulfill(Order $order, Service $service, Supplier $supplier, int $id): Service
    {
        return Cache::lock('rainyun:purchase:'.$service->id, 90)->block(3, function () use ($order, $service, $supplier, $id): Service {
            RainyunBinding::require($service, $supplier, $id);
            $order->refresh();
            if ((int) $order->status === OrderStatus::COMPLETED) {
                return $service;
            }
            $snapshot = (array) $order->config_pricing_snapshot;
            $meta = (array) ($snapshot['meta'] ?? []);
            throw_if(($meta['provider'] ?? '') !== 'rainyun_rcs', new BusinessException('订单不属于雨云 RCS 插件', 42200));
            $quote = (array) ($meta['quote'] ?? []);
            $path = match ($quote['kind'] ?? '') {
                'disk' => 'edisk/', 'traffic' => 'traffic/charge', 'gpu_time' => 'webbar/charge', 'plan' => 'upgrade', 'ip' => 'eip/', default => throw new BusinessException('插件订单类型无效', 42200)
            };
            throw_if(in_array($meta['state'] ?? '', ['submitted', 'uncertain', 'rejected'], true), new BusinessException('该雨云操作已提交或结果不明，请核对上游；自动重试已停止', 42200));
            if (($meta['state'] ?? '') !== 'applied') {
                $detail = $this->client->detail($supplier, $id);
                throw_if($this->fingerprint($detail, $quote['kind']) !== ($quote['fingerprint'] ?? ''), new BusinessException('上游资源已变化，原报价不能继续执行，请联系管理员处理账单', 42200));
                $snapshot['meta']['state'] = 'submitted';
                $order->forceFill(['config_pricing_snapshot' => $snapshot])->save();
                try {
                    $this->client->request($supplier, 'POST', $this->client->hostPath($id, $path), (array) $quote['body']);
                } catch (RainyunApiException $e) {
                    $snapshot['meta']['state'] = $e->uncertain ? 'uncertain' : 'rejected';
                    $order->forceFill(['config_pricing_snapshot' => $snapshot])->save();
                    throw $e;
                }
                $snapshot['meta']['state'] = 'applied';
                $order->forceFill(['config_pricing_snapshot' => $snapshot])->save();
            }
            DB::transaction(function () use ($order, $service, $quote): void {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ((int) $lockedOrder->status === OrderStatus::COMPLETED) {
                    return;
                }
                $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
                if ((int) $quote['monthly_delta'] !== 0) {
                    $lockedService->forceFill(RainyunBilling::renewalFields($lockedService, (int) $quote['monthly_delta']))->save();
                }
                if ($quote['cents'] < 0) {
                    RainyunBilling::refund($lockedOrder, -$quote['cents']);
                }
                $lockedOrder->forceFill(['status' => OrderStatus::COMPLETED])->save();
            });
            $order->refresh();

            return $service->fresh();
        });
    }

    private function diskUnit(string $type, bool $backup, array $prices): int
    {
        $key = match ($type) {
            'ssd', 'cloud-ssd' => 'disk_ssd_price', 'hdd', 'cloud-hdd' => 'disk_hdd_price', 'chdd' => 'disk_chdd_price', default => throw new BusinessException('云盘类型未配置销售价格', 42200)
        };
        $price = RainyunValues::cents($prices[$key] ?? '', '云盘销售单价');
        throw_if($price <= 0, new BusinessException('云盘销售单价必须大于零', 42200));

        return $price + ($backup ? RainyunValues::cents($prices['disk_backup_price'] ?? '', '备份销售单价') : 0);
    }

    private function fingerprint(array $detail, string $kind): string
    {
        $host = $detail['Data'];
        $basis = ['id' => $host['ID'], 'plan' => $host['PlanID'], 'expires' => $host['ExpDate']];
        if ($kind === 'disk') {
            $basis['disks'] = array_map(fn ($d) => array_intersect_key($d, array_flip(['ID', 'Size', 'Backup', 'Slot', 'DiskType'])), $detail['EDiskList'] ?? []);
            usort($basis['disks'], fn ($a, $b) => $a['ID'] <=> $b['ID']);
        }
        if ($kind === 'ip') {
            $basis['ips'] = array_column($detail['EIPList'] ?? [], 'ID');
            sort($basis['ips']);
        }

        return hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR));
    }
}
