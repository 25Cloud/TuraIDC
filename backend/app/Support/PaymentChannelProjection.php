<?php

declare(strict_types=1);

namespace App\Support;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 「没有第三方支付流水时」的支付渠道投影（余额支付 / 0 元收口）。
 *
 * 为什么需要它：余额支付按设计**不产生 Payment 记录**（见 `PaymentService::payByBalance`），
 * 0 元账单收口更是没有任何资金流（见 `PaymentService::settleZeroAmountInvoice`）。
 * 但账单/订单页必须能看出「这笔是余额付的 / 免费开的」，否则运营和用户都只能看到
 * 「没有支付方式」。三处（`InvoiceService`、`AdminOrderSummaryResource`、
 * `OrderService`）需要同一份口径，所以投影只在这里实现一次：
 *
 * - 金额为 0 → `free`（免费开通）；
 * - 已付且实付 > 0、又没有第三方流水 → `balance`（余额支付）；
 * - 未收口、或「已付标记与金额矛盾」→ 返回 null，不臆造渠道（交给对账）。
 *
 * 文案统一取 `PaymentGatewayCode::label()`，字段名与真实流水保持一致，另加
 * `derived = true` 标明这是推导结果而不是 `payments` 表里的一行——前端可以据此
 * 区分展示，也可以完全不区分。
 */
final class PaymentChannelProjection
{
    /**
     * 账单维度：已收口且没有第三方流水时给出渠道投影。
     *
     * @return array<string, mixed>|null
     */
    public static function forInvoice(Invoice $invoice): ?array
    {
        return self::derive(
            self::invoiceSettled($invoice),
            (float) ($invoice->amount ?? 0),
            (float) ($invoice->paid_amount ?? 0),
            $invoice->paid_at,
        );
    }

    /**
     * 订单维度：优先用账单金额与状态（订单是账单的投影），没有账单就退回订单自身。
     *
     * @return array<string, mixed>|null
     */
    public static function forOrder(Order $order): ?array
    {
        $invoice = $order->invoice;
        $orderSettled = (int) $order->status === OrderStatus::PAID;

        if (! $invoice instanceof Invoice) {
            return self::derive(
                $orderSettled,
                (float) ($order->amount ?? 0),
                (float) ($order->paid_amount ?? 0),
                $order->paid_at,
            );
        }

        $invoicePaid = (float) ($invoice->paid_amount ?? 0);
        $orderPaid = (float) ($order->paid_amount ?? 0);

        return self::derive(
            self::invoiceSettled($invoice) || $orderSettled,
            (float) ($invoice->amount ?? $order->amount ?? 0),
            $invoicePaid > 0 ? $invoicePaid : $orderPaid,
            $order->paid_at ?? $invoice->paid_at,
        );
    }

    /**
     * 渠道投影的唯一实现。
     *
     * @return array<string, mixed>|null
     */
    public static function derive(bool $settled, float $amount, float $paidAmount, DateTimeInterface|string|null $paidAt): ?array
    {
        if (! $settled) {
            return null;
        }

        $amount = round($amount, 2);
        $paidAmount = round($paidAmount, 2);

        if ($amount > 0 && $paidAmount <= 0) {
            // 已付标记与金额矛盾：不猜渠道，留给对账处理。
            return null;
        }

        $gateway = $amount <= 0 ? PaymentGatewayCode::FREE : PaymentGatewayCode::BALANCE;

        return [
            'id' => 0,
            'payment_no' => '',
            'trade_no' => '',
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => PaymentGatewayCode::label($gateway),
            'amount' => number_format($amount <= 0 ? 0.0 : $paidAmount, 2, '.', ''),
            'status' => PaymentStatus::SUCCESS,
            'status_label' => PaymentStatus::$labels[PaymentStatus::SUCCESS] ?? '成功',
            'paid_at' => self::formatPaidAt($paidAt),
            'derived' => true,
        ];
    }

    private static function invoiceSettled(Invoice $invoice): bool
    {
        return in_array((int) $invoice->status, [
            InvoiceStatus::PAID,
            InvoiceStatus::PARTIALLY_REFUNDED,
            InvoiceStatus::REFUNDED,
        ], true);
    }

    /**
     * 真实支付流水的统一投影（`derived = false`）。
     *
     * 与推导投影同一组字段名，前端拿到的 JSON 形状完全一致：有流水就是流水，
     * 没流水就是推导，唯一差别是 `derived` 与 `id/payment_no` 是否为空。
     *
     * @return array<string, mixed>
     */
    public static function fromPayment(Payment $payment): array
    {
        $gateway = $payment->gatewayKey();

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'gateway' => $gateway,
            'gateway_key' => $gateway,
            'gateway_label' => PaymentGatewayCode::label($gateway),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'status_label' => PaymentStatus::$labels[(int) $payment->status] ?? (string) $payment->status,
            'paid_at' => self::formatPaidAt($payment->paid_at),
            'derived' => false,
        ];
    }

    /**
     * 从一组流水里挑「主支付单」：优先成功/已退款且不是重复入账的那条。
     *
     * 唯一实现，避免账单、订单、客户端三处各写一遍过滤条件（判重字段是
     * `callback_raw.duplicate_paid`，改动时只该改这里）。
     *
     * @param  iterable<int, Payment>  $payments
     */
    /**
     * 统一把「数据库里的时间属性」格式化成秒级时间串。
     *
     * 模型的时间属性 PHPStan 看到的是 string（`@property` 由 casts 决定运行时类型），
     * 这里同时接受 Carbon 与字符串，避免每个调用点各写一份 `?->format()` 或 `Carbon::parse()`。
     */
    private static function formatPaidAt(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    public static function primaryThirdPartyPayment(iterable $payments): ?Payment
    {
        $collection = collect($payments)
            ->filter(fn (Payment $payment): bool => $payment->isThirdPartyGateway())
            ->values();

        $settled = static fn (Payment $payment): bool => in_array(
            (int) $payment->status,
            [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED],
            true,
        );

        return $collection
            ->first(fn (Payment $payment): bool => $settled($payment)
                && ! (bool) data_get((array) ($payment->callback_raw ?? []), 'duplicate_paid', false))
            ?? $collection->first($settled);
    }
}
