<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 带上下文的续费账单恢复：在 ProvidesRenewalRecovery 基础上接收本地保存的
 * recovery_context（如续费会话快照），驱动可据此做更精确的上游对账。
 */
interface ProvidesContextualRenewalRecovery extends ProvidesRenewalRecovery
{
    /**
     * @param  array<string, mixed>  $recoveryContext  上次续费提交时由驱动返回、本地落库的恢复上下文
     * @return array{payment_completed?: bool, fund_error?: string, host_detail?: array<string, mixed>}|null
     */
    public function recoverRenewInvoiceWithContext(
        Supplier $supplier,
        int $hostId,
        int $upstreamInvoiceId,
        array $recoveryContext = [],
    ): ?array;
}
