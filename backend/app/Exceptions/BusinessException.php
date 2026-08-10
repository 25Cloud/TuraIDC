<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponseBuilder;
use Exception;
use Illuminate\Http\JsonResponse;

class BusinessException extends Exception
{
    protected int $errorCode;

    protected int $httpStatus;

    public function __construct(string $message = '业务异常', int $errorCode = 42200, int $httpStatus = 422)
    {
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 业务异常属于预期内的受控错误，由各调用层按需主动记录日志。
     * 禁止由顶层异常处理器再次上报，避免产生冗余的 ERROR 日志和无效的 Sentry 告警。
     */
    public function report(): bool
    {
        return false;
    }

    public function render(): JsonResponse
    {
        return ApiResponseBuilder::error($this->errorCode, $this->getMessage(), null, $this->httpStatus);
    }
}
