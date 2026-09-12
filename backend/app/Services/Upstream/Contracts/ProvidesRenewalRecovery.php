<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 续费账单崩溃恢复：fund 后进程中断时，按上游账单 ID 查询支付状态并补齐支付，
 * 返回的语义与 renewServiceInvoice 一致，编排层据此完成本地履约收尾。
 */
interface ProvidesRenewalRecovery extends ProvidesRenewal
{
    /**
     * @return array{payment_completed?: bool, fund_error?: string, host_detail?: array<string, mixed>}|null
     */
    public function recoverRenewInvoice(Supplier $supplier, int $hostId, int $upstreamInvoiceId): ?array;
}
