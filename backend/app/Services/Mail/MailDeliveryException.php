<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * 邮件投递失败的分类载体。
 *
 * 为什么需要它：队列重试（`$tries` / `$backoff`）只对**可重试**的失败有意义。
 * 收件人为空/格式非法、模板不存在、总开关关闭这类原因，重投 3 次只会得到 3 条
 * 一样的失败日志和 3 次无用的 SMTP 连接；而连接超时、上游暂时拒绝恰恰需要退避重试。
 * 抛出本异常即可明确表态，判定逻辑集中在 {@see MailDeliveryClassifier}。
 */
final class MailDeliveryException extends RuntimeException
{
    /**
     * @param  string  $failureReason  机器可读原因码（写入日志与 message_logs.error_msg）
     * @param  bool  $retryable  是否值得再投一次
     */
    public function __construct(
        string $message,
        public readonly string $failureReason = 'unknown',
        public readonly bool $retryable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 永久失败：重投也不会成功（收件人非法、模板缺失、渠道关闭）。
     */
    public static function permanent(string $message, string $failureReason = 'permanent', ?Throwable $previous = null): self
    {
        return new self($message, $failureReason, false, $previous);
    }

    /**
     * 暂时失败：通道未配置、连接超时、厂商暂时拒绝，值得按退避再试。
     */
    public static function transient(string $message, string $failureReason = 'transient', ?Throwable $previous = null): self
    {
        return new self($message, $failureReason, true, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
