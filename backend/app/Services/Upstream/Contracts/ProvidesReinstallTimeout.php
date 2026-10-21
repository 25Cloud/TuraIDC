<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

/** 需要执行前置维护步骤的插件显式声明重装请求时限。 */
interface ProvidesReinstallTimeout
{
    public function reinstallTimeoutSeconds(): int;
}
