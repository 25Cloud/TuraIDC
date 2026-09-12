<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 主机暂停/解除暂停具名协议。
 *
 * 实现方直接调用上游暂停语义端点；未实现本接口的驱动（如仅支持通用 REST 的
 * runtime）由编排层回退 PUT /v1/hosts/{hostId}/module/suspend 通用路径。
 */
interface ProvidesHostSuspension
{
    /**
     * @return array<string, mixed> 上游响应（含 status/msg）
     */
    public function suspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array;

    /**
     * @return array<string, mixed> 上游响应（含 status/msg）
     */
    public function unsuspendHost(Supplier $supplier, int $hostId, ?string $jwt = null): array;
}
