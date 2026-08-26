<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\ZjmfUpstreamBinding;
use App\Services\Finance\PaymentService;
use Illuminate\Support\Facades\Log;

/**
 * 上游余额支付（被魔方财务对接）：/apply_credit、/apply_credit_limit。
 *
 * 协议语义（对齐魔方财务自身作为上游时的 PayController::applyCredit）：
 *   use_credit=1 且余额足够 → 扣余额、账单置已付、触发开通，返回 status=1001 + data.hostid[0]=Service id
 *   use_credit=1 且余额不足 → 账单保持待支付，返回 status=200（enough=1 时返回 400）
 *   use_credit=0            → 撤销余额使用，返回 status=200 + data.invoiceid
 * 魔方财务把 1001 映射为成功，并用 hostid[0] 回填本地 dcimid。
 */
class CreditService
{
    public function __construct(
        private readonly PaymentService $payment,
        private readonly CartService $cart,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyCredit(User $user, array $data): array
    {
        $invoiceId = (int) ($data['invoiceid'] ?? 0);
        $useCredit = (bool) ($data['use_credit'] ?? 0);
        $enough = (bool) ($data['enough'] ?? 0);

        // 配置升降级降级/平级（差价<=0）时 checkout_config_upgrade 返回 invoiceid=0，
        // 魔方财务仍会照常调用 apply_credit，此处幂等放行，不查账单、不落库。
        if ($invoiceId <= 0) {
            if (! $useCredit) {
                return ['status' => 200, 'msg' => '操作成功', 'data' => ['invoiceid' => 0]];
            }

            return ['status' => 1001, 'msg' => '无需支付', 'data' => ['hostid' => [], 'invoiceid' => 0]];
        }

        $invoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return ['status' => 400, 'msg' => '账单不存在'];
        }

        // 撤销余额使用（协议兼容：恢复账单原价）
        if (! $useCredit) {
            return ['status' => 200, 'msg' => '操作成功', 'data' => ['invoiceid' => $invoiceId]];
        }

        // 幂等：账单已支付时直接返回已开通的服务
        if ((int) $invoice->status === InvoiceStatus::PAID) {
            $this->persistConfigUpgrade($invoice);
            $serviceId = $this->resolveServiceId($invoice);

            return [
                'status' => 1001,
                'msg' => '支付完成',
                'data' => [
                    'hostid' => $serviceId > 0 ? [$serviceId] : [],
                    'invoiceid' => $invoiceId,
                ],
            ];
        }

        try {
            $this->payment->payByBalance($invoice, $user, [
                'trace_id' => 'zjmf:'.(int) $user->id.':'.$invoiceId,
                'operator' => '魔方财务API',
                'operator_name' => '魔方财务API',
            ]);

            $invoice->refresh();
            $this->persistConfigUpgrade($invoice);
            $serviceId = $this->resolveServiceId($invoice);
            if ($serviceId > 0) {
                $this->cart->bindService($invoiceId, $serviceId);
            }
            $this->recordDownstream($invoice, $data);

            return [
                'status' => 1001,
                'msg' => '支付完成',
                'data' => [
                    'hostid' => $serviceId > 0 ? [$serviceId] : [],
                    'invoiceid' => $invoiceId,
                ],
            ];
        } catch (\Throwable $exception) {
            $message = (string) $exception->getMessage();
            $insufficient = str_contains($message, '余额不足')
                || str_contains($message, 'balance') && str_contains($message, 'insufficient');

            if ($insufficient) {
                if ($enough) {
                    return ['status' => 400, 'msg' => '余额不足'];
                }

                return ['status' => 200, 'msg' => '使用余额成功', 'data' => ['invoiceid' => $invoiceId]];
            }

            Log::warning('[zjmf-upstream] 余额支付失败', [
                'user_id' => (int) $user->id,
                'invoice_id' => $invoiceId,
                'error' => $message,
            ]);

            return ['status' => 400, 'msg' => $message];
        }
    }

    /**
     * 信用额支付：TuraIDC 暂不支持信用额记账，返回业务失败。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyCreditLimit(User $user, array $data): array
    {
        $invoiceId = (int) ($data['invoiceid'] ?? 0);

        $invoice = Invoice::query()
            ->where('user_id', (int) $user->id)
            ->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return ['status' => 400, 'msg' => '账单不存在'];
        }

        return ['status' => 400, 'msg' => '暂不支持信用额支付'];
    }

    /**
     * 配置升级账单支付成功后，把升级后的配置写回服务主账单 config_snapshot，
     * 使下次 upgrade_config_post 的差价计算基于最新配置。
     */
    private function persistConfigUpgrade(Invoice $invoice): void
    {
        if ((string) $invoice->type !== InvoiceType::UPGRADE) {
            return;
        }

        $snapshot = is_array($invoice->config_pricing_snapshot ?? null) ? $invoice->config_pricing_snapshot : [];
        $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
        if (($meta['kind'] ?? '') !== 'config_upgrade') {
            return;
        }

        $config = $meta['config'] ?? null;
        $serviceId = (int) $invoice->service_id;
        if (! is_array($config) || $serviceId <= 0) {
            return;
        }

        $service = Service::query()->with('invoice')->find($serviceId);
        if (! $service instanceof Service || ! $service->invoice instanceof Invoice) {
            return;
        }

        $current = is_array($service->invoice->config_snapshot ?? null) ? $service->invoice->config_snapshot : [];
        $kept = array_intersect_key($current, array_flip([
            'product_full_path', 'product_path_segments',
            'first_product_group_name', 'second_product_group_name', 'third_product_group_name',
        ]));

        $service->invoice->update(['config_snapshot' => array_merge($config, $kept)]);
    }

    private function resolveServiceId(Invoice $invoice): int
    {
        $invoice->loadMissing('service', 'order');

        if ($invoice->service instanceof Service) {
            return (int) $invoice->service->id;
        }

        if ($invoice->order instanceof Order) {
            $orderServiceId = (int) ($invoice->order->getAttribute('service_id') ?? 0);
            if ($orderServiceId > 0) {
                return $orderServiceId;
            }
        }

        return (int) ZjmfUpstreamBinding::query()
            ->where('invoice_id', (int) $invoice->id)
            ->value('service_id');
    }

    /**
     * 支付成功后回填下游回调信息，供状态变更/工单回复推送使用。
     *
     * @param  array<string, mixed>  $data
     */
    private function recordDownstream(Invoice $invoice, array $data): void
    {
        $url = trim((string) ($data['downstream_url'] ?? ''));
        if ($url === '') {
            return;
        }

        try {
            ZjmfUpstreamBinding::query()->updateOrCreate(
                ['user_id' => (int) $invoice->user_id, 'invoice_id' => (int) $invoice->id],
                [
                    'downstream_url' => rtrim($url, '/'),
                    'downstream_token' => (string) ($data['downstream_token'] ?? ''),
                    'downstream_id' => (int) ($data['downstream_id'] ?? 0),
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('[zjmf-upstream] 下游绑定记录失败', [
                'user_id' => (int) $invoice->user_id,
                'invoice_id' => (int) $invoice->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
