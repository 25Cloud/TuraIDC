<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponseBuilder;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

class BusinessException extends Exception
{
    protected int $errorCode;

    protected int $httpStatus;

    public function __construct(string $message = '业务异常', int $errorCode = 42200, int $httpStatus = 422)
    {
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        parent::__construct(self::localize($message), $httpStatus);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * 按当前 locale 取译文。
     *
     * 消息表（backend/lang/{locale}/messages.php）以中文原文为 key，因此查不到时
     * Laravel 会原样返回 key —— 也就是中文原文。未翻译的语言天然回落中文，
     * 不会出现空消息，也不必为每种语言都维护一份完整词表。
     *
     * 放在构造时做，是为了让 700 多个抛出点一处都不用改：业务代码仍然写中文，
     * 翻译只发生在响应渲染之前。非中文消息（少数纯英文技术提示）直接放行。
     */
    private static function localize(string $message): string
    {
        if ($message === '' || preg_match('/[\x{4e00}-\x{9fff}]/u', $message) !== 1) {
            return $message;
        }

        // 必须显式带上 group 前缀：Laravel 会把不含点号的 key 当成 group 名
        // （去查 "消息文本"[null]），永远命中不了 messages.php 里的条目。
        $key = 'messages.' . $message;
        if (! Lang::has($key)) {
            return $message;
        }

        $translated = Lang::get($key);

        return is_string($translated) && $translated !== '' ? $translated : $message;
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