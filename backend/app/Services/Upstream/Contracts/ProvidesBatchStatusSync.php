<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 批量状态同步协议：驱动一次性拉取并归一一批服务实例的上游状态。
 *
 * 与基础 ProvidesStatusSync 的区别：hosting_panel_api transport 只支持
 * 逐台通用 REST 查询（编排层并行拼装），实现本接口的驱动（ZJMF 财务、
 * 演示上游等）内部完成批量请求与状态归一。
 */
interface ProvidesBatchStatusSync extends ProvidesStatusSync
{
    /**
     * @param  array<int, array<string, mixed>>  $items  每项至少包含 service_id / host 标识
     * @return array<string, mixed> 以调用方约定键位归一后的批量结果
     */
    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array;
}
