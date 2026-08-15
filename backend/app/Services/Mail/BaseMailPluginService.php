<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\System\NotificationService;

abstract class BaseMailPluginService
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function sendHtml(string $to, string $subject, string $html, array $context = []): void;

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        return match ($action) {
            'mail.send_html' => $this->handleSendHtml($action, $payload),
            'mail.test_smtp' => $this->handleTestSmtp($action, $payload),
            default => ['success' => false, 'action' => $action, 'message' => '不支持的插件动作', 'data' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function handleSendHtml(string $action, array $payload): array
    {
        $to = (string) ($payload['to'] ?? '');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'action' => $action, 'message' => '收件人邮箱地址无效', 'data' => []];
        }

        $this->sendHtml(
            to: $to,
            subject: (string) ($payload['subject'] ?? ''),
            html: (string) ($payload['html'] ?? ''),
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
        );

        return $this->success($action, ['sent' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function handleTestSmtp(string $action, array $payload): array
    {
        $to = trim((string) ($payload['to'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? '邮箱验证码'));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '') {
            return ['success' => false, 'action' => $action, 'message' => '请填写有效的收件邮箱和主题', 'data' => []];
        }

        $code = $this->verificationCode($payload);
        $body = trim((string) ($payload['body'] ?? $payload['html'] ?? $this->verificationBody($code)));
        $templateCode = (string) ($payload['template_code'] ?? NotificationService::TEMPLATE_EMAIL_CODE);

        $html = $body !== ''
            ? nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
            : '<p>'.htmlspecialchars($this->verificationBody($code), ENT_QUOTES, 'UTF-8').'</p>';

        $this->sendHtml($to, $subject, $html, ['test' => true, 'template_code' => $templateCode, 'code' => $code]);

        return $this->success($action, [
            'sent' => true,
            'to' => $to,
            'subject' => $subject,
            'template_code' => $templateCode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function success(string $action, array $data): array
    {
        return ['success' => true, 'action' => $action, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function verificationCode(array $payload): string
    {
        $code = trim((string) ($payload['code'] ?? ''));

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : (string) random_int(100000, 999999);
    }

    protected function verificationBody(string $code): string
    {
        return "您的邮箱验证码为：{$code}，10分钟内有效。如非本人操作，请忽略此邮件。";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configValue(array $config, string $pluginKey, mixed $default = ''): mixed
    {
        $value = $config[$pluginKey] ?? null;
        if ($value !== null && $value !== '' && $value !== []) {
            return $value;
        }

        return $default;
    }
}
