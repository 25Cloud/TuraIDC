<?php

declare(strict_types=1);

namespace App\Services\Mail;

use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * 投递失败的「可重试 / 永久」判定与原因码。
 *
 * 现状：`NotificationService::sendEmail()` 与各邮件队列任务对失败的处置是「一律抛出」——
 * 队列会一视同仁地重试 3 次。于是收件人为空、模板不存在、邮件总开关关闭这些
 * **再投也不会成功**的原因，也会各跑 3 轮（3 条失败日志 + 3 次无用连接）；
 * 反过来连接超时这类**确实值得重试**的原因也没有明确的分类依据，
 * 只能靠人看日志猜。
 *
 * 这里把判定收敛到一处，供任务层与传输层共用：
 * - 永久：收件人非法、模板缺失、渠道被关闭；
 * - 可重试：通道未配置/未注册、连接超时、厂商拒绝（可能限额/临时故障）。
 *
 * 默认**可重试**：宁可多投一次也不要吞掉可能成功的邮件。
 */
final class MailDeliveryClassifier
{
    public const REASON_INVALID_RECIPIENT = 'invalid_recipient';

    public const REASON_TEMPLATE_MISSING = 'template_missing';

    public const REASON_CHANNEL_DISABLED = 'channel_disabled';

    public const REASON_CHANNEL_UNCONFIGURED = 'channel_unconfigured';

    public const REASON_TRANSPORT_ERROR = 'transport_error';

    public const REASON_PERMANENT = 'permanent';

    public const REASON_TRANSIENT = 'transient';

    /**
     * 文案匹配规则：永久失败在前（先判具体原因），可重试在后。
     *
     * 之所以要匹配文案：现有调用链里这些失败是裸 `RuntimeException` / `BusinessException`
     * （`模板不存在`、`邮件通知未启用`、`邮件驱动 [x] 未注册`…），改类型会波及既有调用方，
     * 因此在这一层按文案归类，等调用方逐步换成 {@see MailDeliveryException} 后可删。
     *
     * @var array<int, array{pattern: string, retryable: bool, reason: string}>
     */
    private const PATTERNS = [
        ['pattern' => '/收件人|邮箱不能为空|邮箱为空|没有收件人|recipient.{0,12}(empty|missing)/iu', 'retryable' => false, 'reason' => self::REASON_INVALID_RECIPIENT],
        ['pattern' => '/invalid address|not a valid (e-?mail|address)|malformed (e-?mail|address)/i', 'retryable' => false, 'reason' => self::REASON_INVALID_RECIPIENT],
        ['pattern' => '/模板不存在|模板已删除|template.{0,12}(not found|does not exist|missing)/iu', 'retryable' => false, 'reason' => self::REASON_TEMPLATE_MISSING],
        ['pattern' => '/邮件通知未启用|通知已停用|notification.{0,12}(disabled|not enabled)/iu', 'retryable' => false, 'reason' => self::REASON_CHANNEL_DISABLED],
        ['pattern' => '/邮件接口配置不完整|邮件驱动.{0,20}未注册|未配置邮件|mail.{0,20}(not configured|driver not registered)/iu', 'retryable' => true, 'reason' => self::REASON_CHANNEL_UNCONFIGURED],
        ['pattern' => '/timeout|timed out|connection (refused|reset|closed)|could not connect|temporar|try again|too many|rate.?limit/iu', 'retryable' => true, 'reason' => self::REASON_TRANSPORT_ERROR],
    ];

    public static function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof MailDeliveryException) {
            return $exception->isRetryable();
        }

        // 参数非法（含收件人格式）属于调用方错误，重投没有意义
        if ($exception instanceof InvalidArgumentException) {
            return false;
        }

        // SMTP/HTTP 传输层异常：连接、超时、厂商 4xx/5xx 一律值得再试
        if ($exception instanceof TransportExceptionInterface) {
            return true;
        }

        foreach (self::PATTERNS as $rule) {
            if (preg_match($rule['pattern'], $exception->getMessage()) === 1) {
                return $rule['retryable'];
            }
        }

        return true;
    }

    /**
     * 机器可读原因码，写进日志与 `message_logs.error_msg`，便于按原因聚合。
     */
    public static function reason(Throwable $exception): string
    {
        if ($exception instanceof MailDeliveryException) {
            return $exception->failureReason;
        }

        if ($exception instanceof InvalidArgumentException) {
            return self::REASON_INVALID_RECIPIENT;
        }

        if ($exception instanceof TransportExceptionInterface) {
            return self::REASON_TRANSPORT_ERROR;
        }

        foreach (self::PATTERNS as $rule) {
            if (preg_match($rule['pattern'], $exception->getMessage()) === 1) {
                return $rule['reason'];
            }
        }

        return self::REASON_TRANSIENT;
    }

    /**
     * 写日志用的一行摘要：原因码 + 是否可重试，避免把整段异常栈塞进日志。
     *
     * @return array{failure_reason: string, retryable: bool}
     */
    public static function summarize(Throwable $exception): array
    {
        return [
            'failure_reason' => self::reason($exception),
            'retryable' => self::isRetryable($exception),
        ];
    }
}
