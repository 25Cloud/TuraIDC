<?php

namespace App\Constants;

class FinanceLedgerEventType
{
    public const INVOICE_PAYMENT = 'invoice_payment';

    public const INVOICE_REFUND = 'invoice_refund';

    public const RECHARGE = 'recharge';

    public const MANUAL_RECHARGE = 'manual_recharge';

    public const MANUAL_DEDUCTION = 'manual_deduction';

    public const REFERRAL_CREDIT_CASH = 'referral_credit_cash';

    public const SYSTEM_ADJUSTMENT = 'system_adjustment';

    /** 实名认证费用扣款 */
    public const VERIFICATION_FEE = 'verification_fee';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const CATEGORY_INVOICE = 'invoice';

    public const CATEGORY_BALANCE = 'balance';

    public const CATEGORY_REWARD = 'reward';

    public const CATEGORY_ADJUSTMENT = 'adjustment';

    public static function normalize(string $eventType): string
    {
        return match (trim($eventType)) {
            'consume' => self::INVOICE_PAYMENT,
            'refund' => self::INVOICE_REFUND,
            'admin_deduct', 'deduct' => self::MANUAL_DEDUCTION,
            'adjust' => self::SYSTEM_ADJUSTMENT,
            'recharge' => self::RECHARGE,
            'manual_recharge' => self::MANUAL_RECHARGE,
            'manual_deduction' => self::MANUAL_DEDUCTION,
            'invoice_payment' => self::INVOICE_PAYMENT,
            'invoice_refund' => self::INVOICE_REFUND,
            'referral_withdraw_approved', 'referral_credit_cash' => self::REFERRAL_CREDIT_CASH,
            default => trim($eventType) !== '' ? trim($eventType) : self::SYSTEM_ADJUSTMENT,
        };
    }

    public static function label(string $eventType): string
    {
        return self::labels()[self::normalize($eventType)] ?? self::normalize($eventType);
    }

    public static function labels(): array
    {
        return [
            self::INVOICE_PAYMENT => '账单支付',
            self::INVOICE_REFUND => '账单退款',
            self::RECHARGE => '充值到账',
            self::MANUAL_RECHARGE => '手动充值',
            self::MANUAL_DEDUCTION => '手动扣款',
            self::REFERRAL_CREDIT_CASH => '奖励转余额',
            self::SYSTEM_ADJUSTMENT => '系统调账',
            self::VERIFICATION_FEE => '实名认证费用',
        ];
    }

    public static function direction(string $eventType, float $amount = 0): string
    {
        $normalized = self::normalize($eventType);

        return match ($normalized) {
            self::INVOICE_PAYMENT, self::MANUAL_DEDUCTION, self::VERIFICATION_FEE => self::DIRECTION_OUT,
            self::INVOICE_REFUND, self::RECHARGE, self::MANUAL_RECHARGE, self::REFERRAL_CREDIT_CASH => self::DIRECTION_IN,
            default => $amount < 0 ? self::DIRECTION_OUT : self::DIRECTION_IN,
        };
    }

    public static function category(string $eventType): string
    {
        return match (self::normalize($eventType)) {
            self::INVOICE_PAYMENT, self::INVOICE_REFUND => self::CATEGORY_INVOICE,
            self::RECHARGE, self::MANUAL_RECHARGE, self::MANUAL_DEDUCTION, self::SYSTEM_ADJUSTMENT, self::VERIFICATION_FEE => self::CATEGORY_BALANCE,
            self::REFERRAL_CREDIT_CASH => self::CATEGORY_REWARD,
            default => self::CATEGORY_ADJUSTMENT,
        };
    }

    public static function allowedFilterValues(): array
    {
        return [
            self::INVOICE_PAYMENT,
            self::INVOICE_REFUND,
            self::RECHARGE,
            self::MANUAL_RECHARGE,
            self::MANUAL_DEDUCTION,
            self::REFERRAL_CREDIT_CASH,
            self::SYSTEM_ADJUSTMENT,
            self::VERIFICATION_FEE,
        ];
    }
}
