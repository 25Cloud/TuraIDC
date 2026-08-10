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
        $fromName = trim((string) ($account['from_name'] ?? config('app.name', 'EwyFinance')));

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
        Config::set('mail.from.address', $username);
        Config::set('mail.from.name', $fromName);

        app('mail.manager')->forgetMailers();

        Mail::html($html, function ($message) use ($to, $subject, $username, $fromName): void {
            $message->to($to)->subject($subject)->from($username, $fromName);
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
