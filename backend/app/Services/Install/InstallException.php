<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Exceptions\BusinessException;

/**
 * 安装流程中的可预期错误，消息面向安装者展示。
 */
class InstallException extends BusinessException
{
}
