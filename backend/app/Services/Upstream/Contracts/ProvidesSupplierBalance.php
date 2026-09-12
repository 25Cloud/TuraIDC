<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 供应商账户余额查询。余额语义口径：返回结构含 balance 数值字符串，
 * 缺失或非数值由调用方按「查询失败」处理，绝不降级为 0（避免假告警）。
 */
interface ProvidesSupplierBalance
{
    /**
     * @return array{balance?: mixed, currency?: mixed, data?: array<string, mixed>}
     */
    public function getBalance(Supplier $supplier): array;
}
