<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Models\AdminUser;
use App\Models\ReferralWithdrawal;
use App\Services\Referral\ReferralService;

class AdminReferralWithdrawalActionV2Service
{
    public function __construct(
        private readonly ReferralService $referrals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function approve(
        ReferralWithdrawal $withdrawal,
        AdminUser $operator,
        ?string $remark = null,
        ?string $traceId = null,
    ): array {
        $record = $this->referrals->processWithdrawal(
            withdrawal: $withdrawal,
            action: 'approve',
            operatorUserId: (int) $operator->id,
            operator: $operator->username ?: 'admin',
            remark: $remark,
            traceId: $traceId,
        );

        return $this->result($record, 'approval', '提现已通过');
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(
        ReferralWithdrawal $withdrawal,
        AdminUser $operator,
        string $remark,
        ?string $traceId = null,
    ): array {
        $record = $this->referrals->processWithdrawal(
            withdrawal: $withdrawal,
            action: 'reject',
            operatorUserId: (int) $operator->id,
            operator: $operator->username ?: 'admin',
            remark: $remark,
            traceId: $traceId,
        );

        return $this->result($record, 'rejection', '提现已拒绝');
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmPayment(
        ReferralWithdrawal $withdrawal,
        AdminUser $operator,
        string $paymentNo,
        ?string $remark = null,
        ?string $traceId = null,
    ): array {
        $record = $this->referrals->confirmWithdrawalPayment(
            withdrawal: $withdrawal,
            operatorUserId: (int) $operator->id,
            operator: $operator->username ?: 'admin',
            paymentNo: $paymentNo,
            remark: $remark,
            traceId: $traceId,
        );

        return $this->result($record, 'payment_confirmation', '打款确认成功');
    }

    /**
     * @return array<string, mixed>
     */
    private function result(ReferralWithdrawal $record, string $type, string $message): array
    {
        return [
            'id' => (int) $record->id,
            'status' => 'completed',
            'message' => $message,
            'detail' => [
                'type' => $type,
                'withdrawal' => [
                    'id' => (int) $record->id,
                    'status' => (int) $record->status,
                    'method' => (string) $record->method,
                    'amount' => number_format((float) $record->amount, 2, '.', ''),
                    'processed_at' => $record->processed_at?->format('Y-m-d H:i:s'),
                ],
            ],
        ];
    }
}
