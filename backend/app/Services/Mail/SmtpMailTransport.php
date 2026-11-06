<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SmtpMailTransport
{
    /**
     * @param  array<string, mixed>  $account
     */
    public function sendHtml(array $account, string $to, string $subject, string $html): void
    {
        // 收件人先判：空/非法时抛永久失败，让调用方一次判死而不是按队列重投 3 次。
        // 这一步必须在真正连 SMTP 之前——坏地址重投永远不会成功。
        $recipient = MailRecipient::normalize($to);
        if ($recipient === null) {
            throw MailDeliveryException::permanent(
                '收件人邮箱为空或格式非法',
                MailDeliveryClassifier::REASON_INVALID_RECIPIENT,
            );
        }

        $host = trim((string) ($account['host'] ?? ''));
        $port = (int) (($account['port'] ?? 0) ?: 0);
        $username = trim((string) ($account['username'] ?? ''));
        $password = (string) ($account['password'] ?? '');
        $fromName = str_replace(["\r", "\n"], '', trim((string) ($account['from_name'] ?? config('app.name', '图拉云'))));
        // 发件人地址允许与 SMTP 账号不同：未配置（留空）时回退使用账号，保持老配置行为不变。
        $fromAddress = trim((string) ($account['from_address'] ?? ''));
        if ($fromAddress === '') {
            $fromAddress = $username;
        }

        if ($host === '' || $port <= 0 || $username === '' || $password === '') {
            // 通道未配置属于「站长还没配好」：按可重试处理（配好之后重投即可成功）
            throw MailDeliveryException::transient(
                '邮件接口配置不完整',
                MailDeliveryClassifier::REASON_CHANNEL_UNCONFIGURED,
            );
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.mailers.smtp.encryption', $this->resolveEncryption(
            $port,
            isset($account['encryption']) ? (string) $account['encryption'] : null
        ));
        Config::set('mail.mailers.smtp.timeout', $this->resolveTimeoutSeconds(
            isset($account['timeout_seconds']) ? (int) $account['timeout_seconds'] : null
        ));
        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName);

        app('mail.manager')->forgetMailers();

        try {
            Mail::html($html, function ($message) use ($recipient, $subject, $fromAddress, $fromName): void {
                $message->to($recipient)->subject($subject)->from($fromAddress, $fromName);
            });
        } catch (Throwable $exception) {
            // 连接/超时/厂商拒绝：统一归为可重试的传输错误，保留原始异常链便于排错
            throw MailDeliveryException::transient(
                '邮件发送失败：'.$exception->getMessage(),
                MailDeliveryClassifier::REASON_TRANSPORT_ERROR,
                $exception,
            );
        }
    }

    private function resolveEncryption(int $port, ?string $configured): ?string
    {
        $normalized = trim((string) $configured);
        if ($normalized !== '') {
            return strtolower($normalized) === 'none' ? null : strtolower($normalized);
        }

        return match ($port) {
            465 => 'ssl',
            25 => null,
            default => 'tls',
        };
    }

    private function resolveTimeoutSeconds(?int $configured): int
    {
        if ($configured !== null && $configured > 0) {
            return $configured;
        }

        return 8;
    }
}
