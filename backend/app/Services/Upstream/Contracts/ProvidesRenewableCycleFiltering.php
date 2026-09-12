<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Supplier;

/**
 * 上游认可的续费周期过滤：驱动查询上游实际允许的周期集合，
 * null 表示上游不可达，调用方应回退本地周期集合，不得据此报错。
 */
interface ProvidesRenewableCycleFiltering extends ProvidesRenewal
{
    /**
     * @return list<string>|null
     */
    public function renewableCycles(Supplier $supplier, int $hostId): ?array;
}
