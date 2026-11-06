<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\MailDeliveryClassifier;
use App\Services\Mail\MailDeliveryException;
use App\Services\Mail\MailRecipient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * 投递失败分类：决定「按退避重试」还是「一次判死」。
 *
 * 这套规则是本次修复的核心——在此之前所有失败都会被队列一视同仁地重试 3 次，
 * 于是收件人非法、模板缺失这类永久原因也跑满 3 轮。
 */
class MailDeliveryClassifierTest extends TestCase
{
    public function test_invalid_recipient_is_permanent(): void
    {
        $exception = new \InvalidArgumentException('登录提醒邮箱不能为空');

        $this->assertFalse(MailDeliveryClassifier::isRetryable($exception));
        $this->assertSame(MailDeliveryClassifier::REASON_INVALID_RECIPIENT, MailDeliveryClassifier::reason($exception));
    }

    public function test_missing_template_is_permanent(): void
    {
        $exception = new RuntimeException('邮件模板不存在');

        $this->assertFalse(MailDeliveryClassifier::isRetryable($exception));
        $this->assertSame(MailDeliveryClassifier::REASON_TEMPLATE_MISSING, MailDeliveryClassifier::reason($exception));
    }

    public function test_disabled_channel_is_permanent(): void
    {
        $exception = new RuntimeException('邮件通知未启用');

        $this->assertFalse(MailDeliveryClassifier::isRetryable($exception));
        $this->assertSame(MailDeliveryClassifier::REASON_CHANNEL_DISABLED, MailDeliveryClassifier::reason($exception));
    }

    public function test_unconfigured_channel_is_retryable(): void
    {
        // 通道没配好属于站长侧待办：配好后重投即可成功，所以保留重试
        foreach (['邮件接口配置不完整', '邮件驱动 [smtp] 未注册'] as $message) {
            $exception = new RuntimeException($message);

            $this->assertTrue(MailDeliveryClassifier::isRetryable($exception), $message);
            $this->assertSame(MailDeliveryClassifier::REASON_CHANNEL_UNCONFIGURED, MailDeliveryClassifier::reason($exception));
        }
    }

    public function test_transport_error_is_retryable(): void
    {
        $exception = new TransportException('Connection to smtp.example.com:465 timed out');

        $this->assertTrue(MailDeliveryClassifier::isRetryable($exception));
        $this->assertSame(MailDeliveryClassifier::REASON_TRANSPORT_ERROR, MailDeliveryClassifier::reason($exception));

        // 文案层面的传输错误同样可重试（厂商拒绝、限流、暂时不可用）
        foreach (['SMTP Connection refused', '421 too many messages', 'rate limit exceeded'] as $message) {
            $this->assertTrue(MailDeliveryClassifier::isRetryable(new RuntimeException($message)), $message);
        }
    }

    public function test_explicit_delivery_exception_wins_over_message_matching(): void
    {
        $permanent = MailDeliveryException::permanent('收件人邮箱为空或格式非法', MailDeliveryClassifier::REASON_INVALID_RECIPIENT);
        $this->assertFalse(MailDeliveryClassifier::isRetryable($permanent));
        $this->assertSame(MailDeliveryClassifier::REASON_INVALID_RECIPIENT, MailDeliveryClassifier::reason($permanent));

        $transient = MailDeliveryException::transient('邮件发送失败：timeout', MailDeliveryClassifier::REASON_TRANSPORT_ERROR);
        $this->assertTrue(MailDeliveryClassifier::isRetryable($transient));
        $this->assertSame(MailDeliveryClassifier::REASON_TRANSPORT_ERROR, MailDeliveryClassifier::reason($transient));
    }

    public function test_unknown_failure_defaults_to_retryable(): void
    {
        // 宁可多投一次，也不要把可能成功的邮件判死
        $exception = new RuntimeException('某个没见过的错误');

        $this->assertTrue(MailDeliveryClassifier::isRetryable($exception));
        $this->assertSame(MailDeliveryClassifier::REASON_TRANSIENT, MailDeliveryClassifier::reason($exception));
        $this->assertSame(
            ['failure_reason' => MailDeliveryClassifier::REASON_TRANSIENT, 'retryable' => true],
            MailDeliveryClassifier::summarize($exception),
        );
    }

    public function test_recipient_normalization(): void
    {
        $this->assertSame('demo@example.com', MailRecipient::normalize(' demo@example.com '));
        $this->assertSame('demo@example.com', MailRecipient::normalize('客服 <demo@example.com>'));

        // 空、无 @、多地址、头注入一律视为不可投递
        $this->assertNull(MailRecipient::normalize(''));
        $this->assertNull(MailRecipient::normalize('0'));
        $this->assertNull(MailRecipient::normalize('demo'));
        $this->assertNull(MailRecipient::normalize('a@example.com,b@example.com'));
        $this->assertNull(MailRecipient::normalize("demo@example.com\r\nBcc: evil@example.com"));

        $this->assertTrue(MailRecipient::isValid('demo@example.com'));
        $this->assertFalse(MailRecipient::isValid('not-an-email'));
    }
}
