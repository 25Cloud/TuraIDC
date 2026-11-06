<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Services\Mail\MailDeliveryClassifier;
use App\Services\Mail\MailRecipient;
use Throwable;

/**
 * 邮件类队列任务的「投递失败分类」公共逻辑。
 *
 * 队列本身只有「抛异常＝重试」一种语义。三个具体问题都出在这里：
 * 1. 永久失败的毒消息会被反复重投：收件人为空/非法、模板不存在，重投 3 次全是同样的错；
 * 2. 一个坏收件人会把同一批里其它收件人一起拖下去（异常中断循环）；
 * 3. 可重试失败与永久失败混在一条日志里，排错时分不清「该等」还是「该修配置」。
 *
 * 用法：
 * ```php
 * $to = $this->deliverableRecipient($this->to);
 * if ($to === null) { return; }              // 跳过，不占重试额度
 * $this->guardDelivery(fn () => $service->send(...));
 * ```
 */
trait ClassifiesDeliveryRetries
{
    /**
     * 规范化收件人；空/非法返回 null，调用方应直接跳过并留一条 warn 日志。
     */
    protected function deliverableRecipient(?string $address): ?string
    {
        return MailRecipient::normalize($address);
    }

    /**
     * 执行一次投递。
     *
     * - 可重试失败：原样抛出，交给队列按 `$backoff` 重投，直到 `$tries` 用尽转终态；
     * - 永久失败：调用 `fail()` 立即判死（`$this->fail()` 会抛 ManuallyFailedException，
     *   队列据此直接落 failed_jobs，不再消耗剩余重试次数）。
     */
    protected function guardDelivery(callable $deliver): void
    {
        try {
            $deliver();
        } catch (Throwable $exception) {
            if (MailDeliveryClassifier::isRetryable($exception)) {
                throw $exception;
            }

            $this->fail($exception);

            // `fail()` 只是在有队列上下文时把任务标失败；脱离队列直接调 handle() 时它是空操作，
            // 这时必须把异常抛出去，否则永久失败会被静默吞掉（既无 failed_jobs 也无日志）。
            if ($this->job === null) {
                throw $exception;
            }
        }
    }

    /**
     * 本次投递的稳定标识：队列任务 uuid（重试期间不变）。
     *
     * 交给 NotificationService 后，同一次投递的多次尝试只留一条 `message_logs`
     * （成功写 success，永久失败写 failed+原因码），不再每次重试插一行。
     * 脱离队列直接调用 handle() 时返回 null，服务端按「一次调用一条日志」处理。
     */
    protected function deliveryKey(): ?string
    {
        try {
            $uuid = $this->job?->uuid();
        } catch (Throwable) {
            return null;
        }

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * `failed()` 里补充的排错上下文：原因码 + 是否可重试 + 已尝试次数。
     *
     * @return array<string, mixed>
     */
    protected function deliveryFailureContext(Throwable $exception): array
    {
        $context = [
            'failure_reason' => MailDeliveryClassifier::reason($exception),
            'retryable' => MailDeliveryClassifier::isRetryable($exception),
        ];

        try {
            $context['attempts'] = $this->attempts();
        } catch (Throwable) {
            // 非队列上下文（单测里直接 new）时 attempts() 不可用，忽略即可
        }

        return $context;
    }
}
