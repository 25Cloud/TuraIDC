<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 账单化续费协议：驱动一次性完成「创建上游续费账单 + 余额支付」，
 * 返回上游账单 ID 与恢复上下文，供编排层落 checkpoint 与崩溃恢复。
 *
 * 与基础 ProvidesRenewal（renewHost 通用协议）的区别：hosting_panel_api
 * transport 只有 renewHost()，续费账单的 fund/对账由编排层完成；
 * 实现本接口的驱动把整个账单会话封装在驱动内部。
 */
interface ProvidesInvoiceRenewal extends ProvidesRenewal
{
    /**
     * @return array{upstream_invoice_id?: int, host_detail?: array<string, mixed>, payment_completed?: bool, fund_error?: string, recovery_context?: array<string, mixed>}
     */
    public function renewServiceInvoice(Supplier $supplier, int $hostId, string $billingCycle): array;
}
