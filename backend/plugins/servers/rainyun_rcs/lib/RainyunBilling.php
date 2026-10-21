<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Constants\FinanceLedgerEventType;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Order;
use App\Models\Service;
use App\Services\Finance\FinanceDocumentService;
use App\Services\User\AccountService;

final class RainyunBilling
{
    public static function renewalFields(Service $service, int $monthlyDelta): array
    {
        $amount = RainyunValues::cents((string) $service->amount) + $monthlyDelta * RainyunValues::months($service->billing_cycle);
        throw_if($amount <= 0, new BusinessException('调整后续费金额无效，请核对商品价格', 42200));
        $pricing = $service->resolveRenewPricingConfig();
        foreach (['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12] as $cycle => $months) {
            foreach (['base_amount', 'manual_amount'] as $field) {
                if (($pricing[$cycle][$field] ?? null) === null) {
                    continue;
                }
                $next = RainyunValues::cents((string) $pricing[$cycle][$field]) + $monthlyDelta * $months;
                throw_if($next <= 0, new BusinessException('调整后续费周期金额无效，请核对锁定价格', 42200));
                $pricing[$cycle][$field] = RainyunValues::money($next);
            }
        }

        return ['amount' => RainyunValues::money($amount), 'locked_pricing' => $pricing];
    }

    /** 调用方持有订单行锁并处于事务中，退费、流水和完成状态一起提交。 */
    public static function refund(Order $order, int $cents): void
    {
        $invoice = $order->invoice;
        throw_if(! $invoice || $cents <= 0, new BusinessException('云盘退费账单无效', 42200));
        $accounts = app(AccountService::class);
        $current = RainyunValues::cents(number_format($accounts->cashBalance((int) $order->user_id, true), 2, '.', ''));
        $balance = $accounts->setCashBalance((int) $order->user_id, RainyunValues::money($current + $cents));
        $reason = '雨云云盘剩余服务费返还，调整订单 '.$order->order_no;
        $trace = 'rainyun-resource-refund:'.$order->id;
        $transaction = AccountTransaction::query()->create([
            'user_id' => $order->user_id, 'account_type' => 'cash', 'event_type' => FinanceLedgerEventType::INVOICE_REFUND,
            'change_amount' => RainyunValues::money($cents), 'balance_after' => $balance,
            'source_type' => 'invoice', 'source_id' => $invoice->id, 'origin_type' => 'invoice', 'origin_id' => $invoice->id,
            'remark' => $reason, 'operator' => '雨云 RCS 插件', 'trace_id' => $trace,
        ]);
        app(FinanceDocumentService::class)->createBalanceRefundDocuments($invoice, null, $transaction, $cents / 100, $reason,
            ['operator_type' => 'system', 'operator_name' => '雨云 RCS 插件', 'trace_id' => $trace]);
    }
}
