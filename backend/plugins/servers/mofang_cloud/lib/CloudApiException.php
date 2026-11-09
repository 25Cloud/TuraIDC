<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use App\Exceptions\BusinessException;

/**
 * 魔方云接口调用失败。
 *
 * 继承平台业务异常，让控制器/控制台把上游错误原样透出，而不是兜底成 500。
 * 同时保留 HTTP 状态码与代际，便于日志定位「是连不上、还是签错、还是代际选错」。
 */
final class CloudApiException extends BusinessException
{
    public function __construct(
        string $message,
        private readonly ?int $httpCode = null,
        private readonly ?CloudApiMode $mode = null,
    ) {
        parent::__construct($message, 42200);
    }

    public function httpCode(): ?int
    {
        return $this->httpCode;
    }

    public function mode(): ?CloudApiMode
    {
        return $this->mode;
    }
}
