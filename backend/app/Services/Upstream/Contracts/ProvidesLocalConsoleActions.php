<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

use App\Models\Service;
use App\Models\Supplier;

/** 插件操作需要本地账单时，由平台传入已鉴权的实例，禁止从请求体认领实例。 */
interface ProvidesLocalConsoleActions
{
    public function submitServiceConsoleAction(Service $service, Supplier $supplier, int $hostId, array $data): array;
}
