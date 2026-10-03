<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SmtpMailTransport
{
    /**
     * @param  array<string, mixed>  $account
     */
    public function sendHtml(array $account, string $to, string $subject, string $html): void
    {
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
            throw new \RuntimeException('邮件接口配置不完整');
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

        Mail::html($html, function ($message) use ($to, $subject, $fromAddress, $fromName): void {
            $message->to($to)->subject($subject)->from($fromAddress, $fromName);
        });
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
