<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 上游实例终止（销毁）能力。
 *
 * 对齐魔方财务下游 Host::terminate 协议：POST /host/cancel {id, type: 'Immediate', reason}。
 * 实现已内置幂等：上游实例不存在时视为已删除并返回成功标记。
 */
interface ProvidesHostTermination
{
    public function terminateHost(Supplier $supplier, int $hostId, string $reason = '', ?string $jwt = null): array;
}
