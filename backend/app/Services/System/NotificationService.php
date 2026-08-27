<?php

namespace App\Services\System;

use App\Models\MessageLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Mail\MailDriverManager;
use App\Support\PublicUrl;
use App\Support\SiteConfigPayload;
use App\Support\UploadUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class NotificationService
{
    public const TEMPLATE_EMAIL_CODE = '100001';

    public const TEMPLATE_REGISTRATION_SUCCESS = '100002';

    /** 登录提醒：与 TEMPLATE_LOGIN_FAILURE_ALERT / TEMPLATE_LOGIN_LOCATION_ALERT 共用数据库模板 100003 */
    public const TEMPLATE_LOGIN_ALERT = '100003';

    /** 账号绑定/安全提醒：与密码/手机/邮箱变更提醒共用数据库模板 100004 */
    public const TEMPLATE_ACCOUNT_BINDING_ALERT = '100004';

    public const TEMPLATE_SERVICE_ACTIVATED = '100005';

    public const TEMPLATE_SERVICE_SUSPENDED = '100006';

    public const TEMPLATE_SERVICE_RESTORED = '100007';

    public const TEMPLATE_SERVICE_TERMINATED = '100008';

    public const TEMPLATE_SERVICE_REINSTALL_SUCCESS = '100009';

    /** 新订单待支付：与 TEMPLATE_INVOICE_NOTICE 共用数据库模板 100010 */
    public const TEMPLATE_CLIENT_ORDER_PENDING = '100010';

    public const TEMPLATE_INVOICE_NOTICE = '100010';

    public const TEMPLATE_INVOICE_PAYMENT_REMINDER = '100011';

    public const TEMPLATE_INVOICE_SECOND_PAYMENT_REMINDER = '100012';

    public const TEMPLATE_INVOICE_OVERDUE_REMINDER = '100013';

    public const TEMPLATE_AUTO_RENEW_UPCOMING = '100014';

    public const TEMPLATE_AUTO_RENEW_NOTICE = '100015';

    /** 付款成功通知：与 TEMPLATE_MANUAL_PAYMENT_CONFIRM 共用数据库模板 100016 */
    public const TEMPLATE_PAYMENT_SUCCESS = '100016';

    public const TEMPLATE_MANUAL_PAYMENT_CONFIRM = '100016';

    public const TEMPLATE_INVOICE_REFUND = '100017';

    public const TEMPLATE_SERVICE_RENEW_REMINDER = '100018';

    public const TEMPLATE_SERVICE_SECOND_RENEW_REMINDER = '100019';

    public const TEMPLATE_CREDIT_INVOICE_CREATED = '100020';

    public const TEMPLATE_ADMIN_ORDER_CREATED = '100021';

    public const TEMPLATE_ADMIN_ORDER_PAID = '100022';

    public const TEMPLATE_TICKET_OPENED = '100023';

    public const TEMPLATE_TICKET_STAFF_REPLY = '100024';

    public const TEMPLATE_TICKET_AUTO_CLOSED = '100025';

    public const TEMPLATE_TICKET_CREATED = '100026';

    public const TEMPLATE_TICKET_CLIENT_REPLY = '100027';

    public const TEMPLATE_ADMIN_LOGIN_ALERT = '100028';

    public const TEMPLATE_SERVICE_UNSUSPEND_FAILED = '100029';

    public const TEMPLATE_ADMIN_SUPPLIER_LOW_BALANCE = '100030';

    public const TEMPLATE_ADMIN_SUPPLIER_PROVISION_FAILED = '100031';

    public const TEMPLATE_LOGIN_FAILURE_ALERT = '100003';

    public const TEMPLATE_LOGIN_LOCATION_ALERT = '100003';

    public const TEMPLATE_PASSWORD_CHANGED_ALERT = '100004';

    public const TEMPLATE_PHONE_CHANGED_ALERT = '100004';

    public const TEMPLATE_EMAIL_CHANGED_ALERT = '100004';

    public function __construct(
        private readonly MailDriverManager $mailDriverManager,
        private ?IntegrationDriverBindingResolver $driverBindingResolver = null,
        private ?NotificationTemplateService $notificationTemplateService = null,
    ) {}

    public function sendEmail(string $to, string $subject, string $content, ?string $templateCode = null): void
    {
        if ($this->shouldSkipDisabledTemplate('email', $templateCode)) {
            return;
        }

        $logContext = $this->createEmailLog($to, $subject, $content, $templateCode);

        try {
            if (! $this->isEmailEnabled()) {
                throw new \RuntimeException('邮件通知未启用');
            }

            $this->mailDriverManager->resolve()->sendHtml($to, $subject, $this->inlineEmailCss($content), [
                'template_code' => $templateCode,
            ]);

            $this->updateEmailLog($logContext, [
                'status' => 'success',
                'error_msg' => null,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->updateEmailLog($logContext, [
                'status' => 'failed',
                'error_msg' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function sendTemplateEmail(string $to, string $templateCode, array $params = []): void
    {
        $template = $this->notificationTemplates()->find('email', $templateCode);
        if (! is_array($template)) {
            throw new \RuntimeException('邮件模板不存在');
        }

        if (! $this->templatePayloadIsEnabled($template)) {
            return;
        }

        $subjectTemplate = (string) ($template['subject'] ?? '');
        $contentTemplate = (string) ($template['content'] ?? '');

        $siteName = $this->resolveSiteName();
        $renderParams = array_merge([
            'site_name' => $siteName,
            'site_logo' => $this->resolveSiteLogo(),
        ], $this->stringifyParams($params));

        $subject = $this->renderTemplateText($subjectTemplate, $renderParams);
        $subject = str_replace(["\r", "\n"], '', $subject);
        $content = $this->buildTemplateEmailHtml(
            $subject,
            $this->renderTemplateContent($contentTemplate, $renderParams)
        );

        $this->sendEmail($to, $subject, $content, $templateCode);
    }

    private function notificationTemplates(): NotificationTemplateService
    {
        return $this->notificationTemplateService ??= app(NotificationTemplateService::class);
    }

    private function shouldSkipDisabledTemplate(string $channel, ?string $templateCode): bool
    {
        $templateCode = trim((string) $templateCode);
        if ($templateCode === '') {
            return false;
        }

        return ! $this->notificationTemplates()->isEnabled($channel, $templateCode);
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function templatePayloadIsEnabled(array $template): bool
    {
        return ! array_key_exists('is_enabled', $template) || (bool) $template['is_enabled'];
    }

    public function sendEmailCode(string $to, string $code): void
    {
        $this->sendTemplateEmail($to, self::TEMPLATE_EMAIL_CODE, [
            'code' => $code,
            'expire_minutes' => 10,
        ]);
    }

    public function sendLoginEmailAlertToAddress(string $email, string $displayName, string $loginAt, string $ip, ?string $userAgent = null): void
    {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('登录提醒邮箱不能为空');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $email;
        }

        $this->sendTemplateEmail($email, self::TEMPLATE_LOGIN_ALERT, [
            'display_name' => $displayName,
            'email' => $email,
            'login_at' => $loginAt,
            'ip' => $ip,
            'device' => $this->resolveUserAgentSummary($userAgent),
        ]);
    }

    public function sendLoginEmailAlert(User $user, string $loginAt, string $ip, ?string $userAgent = null): void
    {
        $this->sendLoginEmailAlertToAddress(
            (string) $user->email,
            (string) $user->display_name,
            $loginAt,
            $ip,
            $userAgent
        );
    }

    public function sendLoginFailureEmailAlertToAddress(
        string $email,
        string $displayName,
        string $account,
        string $attemptAt,
        string $ip,
        ?string $userAgent = null
    ): void {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('登录失败提醒邮箱不能为空');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $email;
        }

        $this->sendTemplateEmail($email, self::TEMPLATE_LOGIN_FAILURE_ALERT, [
            'display_name' => $displayName,
            'account' => trim($account),
            'attempt_at' => $attemptAt,
            'ip' => $ip,
            'device' => $this->resolveUserAgentSummary($userAgent),
        ]);
    }

    public function sendLoginLocationEmailAlertToAddress(
        string $email,
        string $displayName,
        string $loginAt,
        string $ip,
        string $previousIp,
        ?string $userAgent = null
    ): void {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('异地登录提醒邮箱不能为空');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $email;
        }

        $this->sendTemplateEmail($email, self::TEMPLATE_LOGIN_LOCATION_ALERT, [
            'display_name' => $displayName,
            'email' => $email,
            'login_at' => $loginAt,
            'ip' => $ip,
            'previous_ip' => trim($previousIp) !== '' ? $previousIp : '无历史记录',
            'device' => $this->resolveUserAgentSummary($userAgent),
        ]);
    }

    public function sendPasswordChangedEmailAlertToAddress(
        string $email,
        string $displayName,
        string $changedAt,
        string $ip,
        ?string $userAgent = null
    ): void {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('密码变更提醒邮箱不能为空');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $email;
        }

        $this->sendTemplateEmail($email, self::TEMPLATE_PASSWORD_CHANGED_ALERT, [
            'display_name' => $displayName,
            'bind_type' => '登录密码',
            'bind_account' => '已变更',
            'bound_at' => $changedAt,
            'changed_at' => $changedAt,
            'ip' => $ip,
            'device' => $this->resolveUserAgentSummary($userAgent),
        ]);
    }

    public function sendPhoneChangedEmailAlertToAddress(
        string $email,
        string $displayName,
        string $oldPhone,
        string $newPhone,
        string $changedAt,
        string $ip,
        ?string $userAgent = null
    ): void {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('手机号变更提醒邮箱不能为空');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $email;
        }

        $this->sendTemplateEmail($email, self::TEMPLATE_PHONE_CHANGED_ALERT, [
            'display_name' => $displayName,
            'bind_type' => '安全手机号',
            'bind_account' => trim($newPhone) !== '' ? $this->maskPhone($newPhone) : '未设置',
            'bound_at' => $changedAt,
            'old_phone' => trim($oldPhone) !== '' ? $this->maskPhone($oldPhone) : '未设置',
            'new_phone' => trim($newPhone) !== '' ? $this->maskPhone($newPhone) : '未设置',
            'changed_at' => $changedAt,
            'ip' => $ip,
            'device' => $this->resolveUserAgentSummary($userAgent),
        ]);
    }

    public function sendEmailChangedEmailAlertToAddress(
        string $oldEmail,
        string $newEmail,
        string $displayName,
        string $changedAt,
        string $ip,
        ?string $userAgent = null
    ): void {
        $recipients = array_values(array_unique(array_filter([
            trim($oldEmail),
            trim($newEmail),
        ])));

        if ($recipients === []) {
            throw new \InvalidArgumentException('邮箱变更提醒邮箱不能为空');
        }

        $resolvedDisplayName = trim($displayName) !== '' ? trim($displayName) : ($recipients[0] ?? '');

        foreach ($recipients as $recipient) {
            $this->sendTemplateEmail($recipient, self::TEMPLATE_EMAIL_CHANGED_ALERT, [
                'display_name' => $resolvedDisplayName,
                'bind_type' => '安全邮箱',
                'bind_account' => trim($newEmail) !== '' ? $this->maskEmail($newEmail) : '未设置',
                'bound_at' => $changedAt,
                'old_email' => trim($oldEmail) !== '' ? $this->maskEmail($oldEmail) : '未设置',
                'new_email' => trim($newEmail) !== '' ? $this->maskEmail($newEmail) : '未设置',
                'changed_at' => $changedAt,
                'ip' => $ip,
                'device' => $this->resolveUserAgentSummary($userAgent),
            ]);
        }
    }

    /**
     * 「对外是否真的会发邮件」的唯一判定源。
     *
     * 除本类内部使用外，管理端插件的测试发信也必须查这里：测试发信走驱动直发，
     * 不受该总开关约束，若不一并提示，管理员会因「测试邮件成功」误判为已配置可用，
     * 而对外发码仍返回「邮件服务暂不可用」。
     */
    public function isEmailEnabled(): bool
    {
        $value = Setting::getValue('notification', 'email_enabled', '0');

        return in_array((string) $value, ['1', 'true', 'on'], true);
    }

    /**
     * @return array{id: int|null}
     */
    // 邮件日志明文保存完整正文，供管理端真实审计；管理员端不做脱敏（项目红线）
    private function createEmailLog(string $to, string $subject, string $content, ?string $templateCode): array
    {
        $traceId = $this->notificationTraceId('email', $templateCode);

        try {
            $log = MessageLog::create(array_merge([
                'channel' => 'email',
                'recipient' => $to,
                'template_code' => $templateCode,
                'subject' => $subject,
                'content' => $content,
                'status' => 'pending',
                'origin_type' => 'email_send',
                'origin_id' => 0,
            ], $this->mailAuditPayload($traceId)));

            return [
                'id' => (int) $log->getKey(),
            ];
        } catch (\Throwable $exception) {
            Log::warning('邮件日志写入失败，已跳过日志写入继续发送', [
                'recipient' => $to,
                'template_code' => $templateCode,
                'message' => $exception->getMessage(),
            ]);
        }

        return [
            'id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mailAuditPayload(string $traceId): array
    {
        $context = $this->driverBindingResolver()->mailContext();

        return [
            'plugin_id' => $context['plugin_id'],
            'driver_key' => $context['driver_key'],
            'trace_id' => $traceId,
        ];
    }

    private function driverBindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->driverBindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }

    private function notificationTraceId(string $channel, ?string $templateCode): string
    {
        $template = trim((string) $templateCode) !== '' ? trim((string) $templateCode) : 'none';

        return substr($channel.':'.$template.':'.str_replace('-', '', (string) Str::uuid()), 0, 64);
    }

    /**
     * @param  array{id: int|null}  $logContext
     * @param  array{status?: string, error_msg?: ?string, sent_at?: mixed}  $attributes
     */
    private function updateEmailLog(array $logContext, array $attributes): void
    {
        $id = isset($logContext['id']) ? (int) $logContext['id'] : 0;

        if ($id <= 0) {
            return;
        }

        try {
            MessageLog::query()->whereKey($id)->update($attributes);
        } catch (\Throwable $exception) {
            Log::warning('邮件日志状态更新失败，已忽略以避免阻断发送流程', [
                'table' => 'message_logs',
                'id' => $id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveUserAgentSummary(?string $userAgent): string
    {
        $ua = mb_strtolower(trim((string) $userAgent));

        if ($ua === '') {
            return '未知设备';
        }

        $platform = match (true) {
            str_contains($ua, 'iphone') || str_contains($ua, 'ios') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'linux') => 'Linux',
            default => '未知平台',
        };

        $browser = match (true) {
            str_contains($ua, 'edg') => 'Edge',
            str_contains($ua, 'chrome') && ! str_contains($ua, 'edg') => 'Chrome',
            str_contains($ua, 'safari') && ! str_contains($ua, 'chrome') => 'Safari',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'micromessenger') => '微信',
            default => '未知浏览器',
        };

        return "{$platform} / {$browser}";
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) <= 7) {
            return $digits;
        }

        return substr($digits, 0, 3).'****'.substr($digits, -4);
    }

    private function maskEmail(string $email): string
    {
        $normalized = trim($email);
        if ($normalized === '' || ! str_contains($normalized, '@')) {
            return $normalized;
        }

        [$name, $domain] = explode('@', $normalized, 2);
        $nameLength = mb_strlen($name);
        if ($nameLength <= 2) {
            return mb_substr($name, 0, 1).'*@'.$domain;
        }

        return mb_substr($name, 0, 2).str_repeat('*', max($nameLength - 2, 1)).'@'.$domain;
    }

    private function stringifyParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalized[trim($key)] = match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $normalized;
    }

    private function resolveSiteName(): string
    {
        $siteName = trim((string) Setting::getValue(
            'basic',
            'site_name',
            config('idc.site_name', config('app.name', '图拉云'))
        ));

        return $siteName !== '' ? $siteName : (string) config('app.name', '图拉云');
    }

    private function resolveSiteLogo(): string
    {
        $siteLogo = trim((string) Setting::getValue('basic', 'site_logo', SiteConfigPayload::DEFAULT_SITE_LOGO));
        if ($siteLogo === '') {
            $siteLogo = SiteConfigPayload::DEFAULT_SITE_LOGO;
        }

        if (preg_match('/^(https?:)?\/\//i', $siteLogo) === 1) {
            return $siteLogo;
        }

        $resolved = UploadUrl::resolve($siteLogo);
        if ($resolved === null || $resolved === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $resolved) === 1) {
            return $resolved;
        }

        return PublicUrl::website($resolved);
    }

    private function renderTemplateText(string $template, array $params): string
    {
        return $this->renderTemplateWithResolver(
            $template,
            $params,
            fn (string $key) => (string) ($params[$key] ?? '')
        );
    }

    private function renderTemplateContent(string $template, array $params): string
    {
        if ($this->looksLikeHtml($template)) {
            return $this->renderTemplateWithResolver(
                $template,
                $params,
                fn (string $key) => htmlspecialchars((string) ($params[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        return $this->convertPlainTextToHtml($this->renderTemplateText($template, $params));
    }

    private function renderTemplateWithResolver(string $template, array $params, callable $resolver): string
    {
        $rendered = preg_replace_callback(
            '/\{\{#([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/su',
            function (array $matches) use ($params) {
                $key = (string) ($matches[1] ?? '');
                $value = $params[$key] ?? '';

                return $this->hasTemplateValue($value) ? (string) ($matches[2] ?? '') : '';
            },
            $template
        ) ?? $template;

        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
            fn (array $matches) => (string) $resolver((string) ($matches[1] ?? '')),
            $rendered ?? $template
        );

        if (! $this->looksLikeHtml($template)) {
            $rendered = preg_replace("/[ \t]+\n/u", "\n", (string) $rendered) ?? (string) $rendered;
            $rendered = preg_replace("/\n{3,}/u", "\n\n", (string) $rendered) ?? (string) $rendered;
        }

        return trim((string) $rendered);
    }

    private function looksLikeHtml(string $template): bool
    {
        $normalized = ltrim(trim($template));

        return preg_match('/^(<!doctype\s+html|<html\b|<body\b)/iu', $normalized) === 1
            || preg_match('/<([a-z][a-z0-9]*)(\s|>)/iu', $normalized) === 1;
    }

    private function convertPlainTextToHtml(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '<p class="mail-empty">暂无邮件内容</p>';
        }

        $blocks = preg_split("/\n{2,}/u", $content) ?: [];
        $htmlBlocks = [];

        foreach ($blocks as $block) {
            $lines = preg_split("/\n/u", trim((string) $block)) ?: [];
            $lineHtml = implode('<br>', array_map(
                fn (string $line) => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                array_filter($lines, fn ($line) => trim((string) $line) !== '')
            ));

            if ($lineHtml !== '') {
                $htmlBlocks[] = '<p>'.$lineHtml.'</p>';
            }
        }

        return implode("\n", $htmlBlocks);
    }

    private function buildTemplateEmailHtml(string $subject, string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            $content = '<p class="mail-empty">暂无邮件内容</p>';
        }

        if ($this->looksLikeFullHtmlDocument($content)) {
            return $content;
        }

        return $this->wrapTemplateEmailHtml($subject, $content);
    }

    private function looksLikeFullHtmlDocument(string $content): bool
    {
        $normalized = ltrim(trim($content));

        return preg_match('/^(<!doctype\s+html|<html\b|<body\b)/iu', $normalized) === 1;
    }

    private function wrapTemplateEmailHtml(string $subject, string $content): string
    {
        $subjectHtml = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$subjectHtml}</title>
</head>
<body>
{$content}
</body>
</html>
HTML;
    }

    private function hasTemplateValue(mixed $value): bool
    {
        return ! in_array($value, [null, '', false], true);
    }

    /**
     * 将 HTML 邮件中的 <style> 内联到各元素，确保 Gmail 等剥离 <head> 的客户端正常渲染。
     */
    private function inlineEmailCss(string $content): string
    {
        $content = trim($content);
        if ($content === '' || ! $this->looksLikeFullHtmlDocument($content)) {
            return $content;
        }

        try {
            return (new CssToInlineStyles)->convert($content);
        } catch (\Throwable) {
            return $content;
        }
    }
}
