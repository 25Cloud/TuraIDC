<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Mail\Smtp\Lib;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Mail\SmtpMailTransport;
use App\Services\System\NotificationService;

class SmtpMailService
{
    public function __construct(
        private readonly PluginConfigRepository $configRepository,
        private readonly SmtpMailTransport $transport,
    ) {}

    public function key(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return 'Single SMTP';
    }

    public function sendHtml(string $to, string $subject, string $html, array $context = []): void
    {
        $config = $this->configRepository->resolvedConfigByDomainAndSlug(PluginDomain::MAIL, 'smtp');

        $this->transport->sendHtml([
            'host' => $this->configValue($config, 'host'),
            'port' => $this->configValue($config, 'port', 465),
            'username' => $this->configValue($config, 'username'),
            'password' => $this->configValue($config, 'password'),
            'from_name' => $this->configValue($config, 'from_name', config('app.name', 'TuraIDC')),
            'encryption' => $this->configValue($config, 'encryption', null),
            'timeout_seconds' => $this->configValue($config, 'timeout_seconds', 8),
        ], $to, $subject, $html);
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        return match ($action) {
            'mail.send_html' => $this->sendHtmlAction($action, $payload),
            'mail.test_smtp' => $this->sendTestAction($action, $payload),
            default => ['success' => false, 'action' => $action, 'message' => 'Unsupported plugin action', 'data' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendHtmlAction(string $action, array $payload): array
    {
        $this->sendHtml(
            to: (string) ($payload['to'] ?? ''),
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
    private function sendTestAction(string $action, array $payload): array
    {
        $to = trim((string) ($payload['to'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? '邮箱验证码'));
        $code = $this->verificationCode($payload);
        $body = trim((string) ($payload['body'] ?? $payload['html'] ?? $this->verificationBody($code)));
        $templateCode = (string) ($payload['template_code'] ?? NotificationService::TEMPLATE_EMAIL_CODE);

        if ($to === '' || $subject === '') {
            throw new BusinessException('缺少必要参数：to、subject', 42200);
        }

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
    private function success(string $action, array $data): array
    {
        return ['success' => true, 'action' => $action, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verificationCode(array $payload): string
    {
        $code = trim((string) ($payload['code'] ?? ''));

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : (string) random_int(100000, 999999);
    }

    private function verificationBody(string $code): string
    {
        return "您的邮箱验证码为：{$code}，10分钟内有效。如非本人操作，请忽略此邮件。";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configValue(array $config, string $pluginKey, mixed $default = ''): mixed
    {
        $value = $config[$pluginKey] ?? null;
        if ($value !== null && $value !== '' && $value !== []) {
            return $value;
        }

        return $default;
    }
}
