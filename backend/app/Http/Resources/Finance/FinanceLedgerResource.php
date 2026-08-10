<?php

namespace App\Http\Resources\Finance;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\PaymentStatus;
use App\Models\AccountTransaction;
use App\Support\AdminPrivacy;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountTransaction */
class FinanceLedgerResource extends JsonResource
{
    public function toArray($request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $amount = (float) ($this->change_amount ?? 0);
        $normalizedEventType = FinanceLedgerEventType::normalize((string) ($this->event_type ?? ''));
        $invoice = $this->whenLoaded('invoice');
        $payment = $this->whenLoaded('payment');
        $user = $this->whenLoaded('user');
        $display = $this->displayMeta($normalizedEventType, $amount, $invoice, $payment);
        $businessScene = $this->businessScene($normalizedEventType, $invoice, $payment);
        $paymentGatewayKey = $payment && method_exists($payment, 'gatewayKey') ? $payment->gatewayKey() : '';

        return [
            'ledger_id' => (int) $this->id,
            'id' => (int) $this->id,
            'account_type' => (string) ($this->account_type ?? 'cash'),
            'event_type' => $normalizedEventType,
            'event_type_label' => FinanceLedgerEventType::label($normalizedEventType),
            'event_category' => FinanceLedgerEventType::category($normalizedEventType),
            'direction' => FinanceLedgerEventType::direction($normalizedEventType, $amount),
            'amount' => number_format(abs($amount), 2, '.', ''),
            'change_amount' => number_format($amount, 2, '.', ''),
            'balance_after' => number_format((float) ($this->balance_after ?? 0), 2, '.', ''),
            'occurred_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'remark' => (string) ($this->remark ?? ''),
            'source_type' => (string) ($this->source_type ?? ''),
            'source_id' => $this->source_id !== null ? (int) $this->source_id : null,
            'origin_type' => (string) ($this->origin_type ?? ''),
            'origin_id' => $this->origin_id !== null ? (int) $this->origin_id : null,
            'operator' => (string) ($this->operator ?? ''),
            'trace_id' => (string) ($this->trace_id ?? ''),
            'business_scene' => $businessScene['key'],
            'business_scene_label' => $businessScene['label'],
            'invoice' => $invoice ? [
                'id' => (int) $invoice->id,
                'invoice_no' => (string) $invoice->invoice_no,
                'type' => (string) $invoice->type,
                'type_label' => (string) ($invoice->type_label ?? InvoiceType::label((string) $invoice->type)),
                'business_scene' => $businessScene['key'],
                'business_scene_label' => $businessScene['label'],
                'status' => (int) $invoice->status,
                'status_label' => InvoiceStatus::$labels[(int) $invoice->status] ?? (string) $invoice->status,
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                'paid_amount' => number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
            ] : null,
            'payment' => $payment ? [
                'id' => (int) $payment->id,
                'payment_no' => (string) $payment->payment_no,
                'gateway' => $paymentGatewayKey,
                'gateway_key' => $paymentGatewayKey,
                'gateway_label' => $display['channel_label'],
                'status' => (int) $payment->status,
                'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? (string) $payment->status,
                'trade_no' => (string) ($payment->trade_no ?? ''),
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            ] : null,
            'user' => $user ? [
                'id' => (int) $user->id,
                'email' => $privacy->email($user->email),
                'nickname' => (string) ($user->nickname ?? ''),
                'display_name' => $privacy->displayName($user->display_name ?? '', $user->email ?? '', $user->phone ?? '', $user->real_name ?? ''),
            ] : null,
            'display' => array_merge($display, [
                'business_scene_label' => $businessScene['label'],
            ]),
        ];
    }

    private function businessScene(string $eventType, mixed $invoice, mixed $payment): array
    {
        if (! $invoice) {
            return match ($eventType) {
                FinanceLedgerEventType::RECHARGE, FinanceLedgerEventType::MANUAL_RECHARGE => ['key' => 'recharge', 'label' => '充值'],
                FinanceLedgerEventType::MANUAL_DEDUCTION, FinanceLedgerEventType::SYSTEM_ADJUSTMENT => ['key' => 'adjustment', 'label' => '调账'],
                FinanceLedgerEventType::REFERRAL_CREDIT_CASH => ['key' => 'referral', 'label' => '推荐奖励'],
                default => ['key' => 'funds', 'label' => '资金变动'],
            };
        }

        $type = InvoiceType::normalize((string) ($invoice->type ?: ($invoice?->order?->type ?? '')));
        $scene = match ($type) {
            InvoiceType::RENEW => $this->isAutoRenew($invoice, $payment)
                ? ['key' => 'auto_renew', 'label' => '自动续费']
                : ['key' => 'manual_renew', 'label' => '手动续费'],
            InvoiceType::NEW_PURCHASE => ['key' => 'purchase', 'label' => '购买'],
            InvoiceType::UPGRADE => ['key' => 'upgrade', 'label' => '附加配置'],
            InvoiceType::RECHARGE => ['key' => 'recharge', 'label' => '充值'],
            InvoiceType::DEDUCTION => ['key' => 'deduction', 'label' => '扣款'],
            InvoiceType::REFERRAL_CREDIT => ['key' => 'referral', 'label' => '推荐奖励'],
            InvoiceType::MANUAL => ['key' => 'manual_invoice', 'label' => '手工账单'],
            default => ['key' => $type !== '' ? $type : 'invoice', 'label' => InvoiceType::label($type !== '' ? $type : 'manual')],
        };

        if ($eventType === FinanceLedgerEventType::INVOICE_REFUND) {
            $scene['key'] .= '_refund';
            $scene['label'] .= '退款';
        }

        return $scene;
    }

    private function isAutoRenew(mixed $invoice, mixed $payment): bool
    {
        $config = is_array($invoice?->config_snapshot ?? null) ? (array) $invoice->config_snapshot : [];
        if ((int) ($config['auto_renew'] ?? 0) === 1) {
            return true;
        }

        $paymentRaw = method_exists($payment, 'callbackPayload') ? $payment->callbackPayload() : [];
        $values = [
            $this->operator ?? '',
            $this->trace_id ?? '',
            $this->remark ?? '',
            $config['created_by'] ?? '',
            $config['auto_renew_trace_id'] ?? '',
            $invoice?->order?->operator ?? '',
            $invoice?->order?->trace_id ?? '',
            $invoice?->order?->remark ?? '',
            $paymentRaw['source'] ?? '',
            $paymentRaw['trace_id'] ?? '',
        ];

        foreach ($values as $value) {
            $text = mb_strtolower((string) $value);
            if (str_contains($text, 'auto_renew') || str_contains($text, 'auto-renew') || str_contains($text, '自动续费') || str_contains($text, 'service-auto-renew')) {
                return true;
            }
        }

        return false;
    }

    private function displayMeta(string $eventType, float $amount, mixed $invoice, mixed $payment): array
    {
        $title = FinanceLedgerEventType::label($eventType);
        $subtitle = trim((string) ($this->remark ?? ''));
        $paymentGatewayKey = $payment && method_exists($payment, 'gatewayKey') ? $payment->gatewayKey() : '';
        $channelLabel = $this->gatewayLabel($paymentGatewayKey);
        $status = null;
        $statusLabel = '--';

        if ($invoice) {
            $status = (int) $invoice->status;
            $statusLabel = InvoiceStatus::$labels[$status] ?? (string) $status;
        } elseif ($payment) {
            $status = (int) $payment->status;
            $statusLabel = PaymentStatus::$labels[$status] ?? (string) $status;
        }

        if ($subtitle === '') {
            $subtitle = match ($eventType) {
                FinanceLedgerEventType::INVOICE_PAYMENT => '关联账单支付',
                FinanceLedgerEventType::INVOICE_REFUND => '关联账单退款',
                FinanceLedgerEventType::RECHARGE, FinanceLedgerEventType::MANUAL_RECHARGE => '资金已入账',
                FinanceLedgerEventType::MANUAL_DEDUCTION => '管理员扣减余额',
                FinanceLedgerEventType::REFERRAL_CREDIT_CASH => '奖励转入现金余额',
                default => '账户资金调整',
            };
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'badge' => FinanceLedgerEventType::label($eventType),
            'badge_type' => $this->badgeType($eventType, $amount),
            'status' => $status,
            'status_label' => $statusLabel,
            'channel_label' => $channelLabel,
            'scene_label' => $this->sceneLabel($eventType),
        ];
    }

    private function badgeType(string $eventType, float $amount): string
    {
        return match ($eventType) {
            FinanceLedgerEventType::RECHARGE,
            FinanceLedgerEventType::MANUAL_RECHARGE,
            FinanceLedgerEventType::INVOICE_REFUND,
            FinanceLedgerEventType::REFERRAL_CREDIT_CASH => 'success',
            FinanceLedgerEventType::MANUAL_DEDUCTION,
            FinanceLedgerEventType::INVOICE_PAYMENT => 'danger',
            default => $amount < 0 ? 'danger' : 'info',
        };
    }

    private function sceneLabel(string $eventType): string
    {
        return match ($eventType) {
            FinanceLedgerEventType::RECHARGE, FinanceLedgerEventType::MANUAL_RECHARGE => '充值',
            FinanceLedgerEventType::INVOICE_PAYMENT, FinanceLedgerEventType::INVOICE_REFUND => '账单',
            FinanceLedgerEventType::MANUAL_DEDUCTION, FinanceLedgerEventType::SYSTEM_ADJUSTMENT => '调账',
            FinanceLedgerEventType::REFERRAL_CREDIT_CASH => '奖励',
            default => '资金',
        };
    }

    private function gatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            'alipay' => '支付宝支付',
            'yipay' => '易支付',
            'wechat' => '微信支付',
            'balance' => '余额支付',
            'bank_transfer' => '银行转账',
            'offline' => '线下支付',
            'free' => '免费确认',
            default => $gateway !== '' ? $gateway : '手动入账',
        };
    }
}
