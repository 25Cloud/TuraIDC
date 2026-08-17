<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Mail\MultiSmtpRoundRobin\Lib;

use App\Exceptions\BusinessException;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Mail\SmtpMailTransport;
use App\Services\System\NotificationService;
use Illuminate\Support\Facades\Cache;

class MultiSmtpRoundRobinService
{
    public function __construct(
        private readonly PluginConfigRepository $configRepository,
        private readonly SmtpMailTransport $transport,
    ) {}

    public function key(): string
    {
        return 'multi_smtp_round_robin';
    }

    public function label(): string
    {
        return '多 SMTP 轮询';
    }

    public function sendHtml(string $to, string $subject, string $html, array $context = []): void
    {
        $config = $this->configRepository->resolvedConfigByDomainAndSlug(PluginDomain::MAIL, 'multi_smtp_round_robin');
        $accounts = array_values(array_filter(
            is_array($config['accounts'] ?? null) ? $config['accounts'] : [],
            static fn (mixed $item): bool => is_array($item) && (bool) ($item['enabled'] ?? true)
        ));

        if ($accounts === []) {
            throw new BusinessException('多 SMTP 插件未配置可用账号', 42200);
        }

        $cooldownSeconds = max((int) ($config['cooldown_seconds'] ?? 60), 1);
        $lastException = null;
        $accountCount = count($accounts);
        $startIndex = $this->nextIndex($accountCount);

        for ($offset = 0; $offset < $accountCount; $offset++) {
            $index = ($startIndex + $offset) % $accountCount;
            $cooldownKey = $this->cooldownKey($index);

            if ((int) Cache::get($cooldownKey, 0) > time()) {
                continue;
            }

            try {
                $this->transport->sendHtml($accounts[$index], $to, $subject, $html);
                Cache::put($this->cursorKey(), ($index + 1) % $accountCount, now()->addDay());

                return;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                Cache::put($cooldownKey, time() + $cooldownSeconds, now()->addSeconds($cooldownSeconds));
            }
        }

        throw new BusinessException($lastException?->getMessage() ?: '多 SMTP 轮询发送失败', 42200);
    }

    public function testSmtp(int $accountIndex, string $to, string $subject, string $body = ''): void
    {
        $config = $this->configRepository->resolvedConfigByDomainAndSlug(PluginDomain::MAIL, 'multi_smtp_round_robin');
        $accounts = is_array($config['accounts'] ?? null) ? array_values($config['accounts']) : [];

        if (! isset($accounts[$accountIndex])) {
            throw new BusinessException('SMTP 账号不存在', 42200);
        }

        $account = $accounts[$accountIndex];
        if (! is_array($account)) {
            throw new BusinessException('SMTP 账号配置无效', 42200);
        }

        $fallbackCode = (string) random_int(100000, 999999);
        $html = $body !== ''
            ? nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
            : '<p>'.htmlspecialchars($this->verificationBody($fallbackCode), ENT_QUOTES, 'UTF-8').'</p>';

        $this->transport->sendHtml($account, $to, $subject, $html);
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        if ($action === 'mail.test_smtp') {
            $accountIndex = (int) ($payload['account_index'] ?? -1);
            $to = trim((string) ($payload['to'] ?? ''));
            $subject = trim((string) ($payload['subject'] ?? '邮箱验证码'));
            $code = $this->verificationCode($payload);
            $body = trim((string) ($payload['body'] ?? $this->verificationBody($code)));
            $templateCode = (string) ($payload['template_code'] ?? NotificationService::TEMPLATE_EMAIL_CODE);

            if ($accountIndex < 0 || $to === '' || $subject === '') {
                return [
                    'success' => false,
                    'action' => $action,
                    'message' => '缺少必要参数：account_index、to、subject',
                    'data' => [],
                ];
            }

            $this->testSmtp($accountIndex, $to, $subject, $body);

            return [
                'success' => true,
                'action' => $action,
                'message' => '测试邮件发送成功',
                'data' => ['sent' => true, 'template_code' => $templateCode],
            ];
        }

        if ($action !== 'mail.send_html') {
            return [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ];
        }

        $this->sendHtml(
            to: (string) ($payload['to'] ?? ''),
            subject: (string) ($payload['subject'] ?? ''),
            html: (string) ($payload['html'] ?? ''),
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
        );

        return [
            'success' => true,
            'action' => $action,
            'data' => ['sent' => true],
        ];
    }

    private function nextIndex(int $count): int
    {
        if ($count <= 1) {
            return 0;
        }

        return ((int) Cache::get($this->cursorKey(), 0)) % $count;
    }

    private function cursorKey(): string
    {
        return 'plugin:mail:multi_smtp_round_robin:cursor';
    }

    private function cooldownKey(int $index): string
    {
        return 'plugin:mail:multi_smtp_round_robin:cooldown:'.$index;
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
}
