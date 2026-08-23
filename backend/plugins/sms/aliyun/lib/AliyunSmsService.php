<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\Aliyun\Lib;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

class AliyunSmsService
{
    public function key(): string
    {
        return 'aliyun';
    }

    public function label(): string
    {
        return '阿里云号码认证';
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        if ($action === 'sms.test') {
            return $this->handleTestAction($action, $payload, $config);
        }

        if ($action === 'sms.send_message') {
            return $this->handleSendMessageAction($action, $payload, $config);
        }

        if ($action === 'sms.fetch_signs') {
            return $this->handleFetchSignsAction($action, $config);
        }

        if ($action === 'sms.verify_code_template') {
            return [
                'success' => true,
                'action' => $action,
                'data' => ['template' => $this->verifyCodeTemplate((string) ($payload['purpose'] ?? 'generic'))],
            ];
        }

        if ($action !== 'sms.send_verify_code') {
            return [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ];
        }

        return $this->handleSendVerifyCodeAction($action, $payload, $config);
    }

    /**
     * 验证码文案模板（${code}/${min} 占位符），随阿里云短信插件维护，不在系统层硬编码。
     */
    private function verifyCodeTemplate(string $purpose): string
    {
        return match ($purpose) {
            'change_phone', 'phone_change', 'update_phone' => '尊敬的客户，您正在进行修改手机号操作，您的验证码为${code}。以上验证码${min}分钟内有效，请注意保密，切勿告知他人。',
            'reset', 'reset_password', 'password_reset' => '尊敬的客户，您正在进行重置密码操作，您的验证码为${code}。以上验证码${min}分钟内有效，请注意保密，切勿告知他人。',
            'bind_phone', 'new_phone' => '尊敬的客户，您正在进行绑定手机号操作，您的验证码为${code}。以上验证码${min}分钟内有效，请注意保密，切勿告知他人。',
            'verify_bound_phone', 'verify_phone' => '尊敬的客户，您正在验证绑定手机号操作，您的验证码为${code}。以上验证码${min}分钟内有效，请注意保密，切勿告知他人。',
            default // login, register, generic
            => '您的验证码为${code}。尊敬的客户，以上验证码${min}分钟内有效，请注意保密，切勿告知他人。',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleTestAction(string $action, array $payload, array $config): array
    {
        $phoneError = $this->validatePhone($payload);
        if ($phoneError !== null) {
            return ['success' => false, 'action' => $action, 'message' => $phoneError, 'data' => []];
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $client = $this->client($config);
        $result = $client->sendVerifyCode(
            phone: $phone,
            code: $this->verificationCode($payload),
            options: is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return [
            'success' => $result['success'] ?? false,
            'action' => $action,
            'message' => ($result['success'] ?? false) ? '测试短信发送成功' : ($result['message'] ?? '发送失败'),
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleSendMessageAction(string $action, array $payload, array $config): array
    {
        $client = $this->client($config);
        $result = $client->sendMessage(
            phone: (string) ($payload['phone'] ?? ''),
            templateCode: (string) ($payload['template_code'] ?? ''),
            content: (string) ($payload['content'] ?? ''),
            options: is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return [
            'success' => $result['success'] ?? false,
            'action' => $action,
            'message' => $result['message'] ?? '',
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleSendVerifyCodeAction(string $action, array $payload, array $config): array
    {
        $phoneError = $this->validatePhone($payload);
        if ($phoneError !== null) {
            return ['success' => false, 'action' => $action, 'message' => $phoneError, 'data' => []];
        }

        $code = trim((string) ($payload['code'] ?? ''));
        if ($code === '') {
            return ['success' => false, 'action' => $action, 'message' => '缺少必要参数：code', 'data' => []];
        }

        $rateLimitError = $this->checkRateLimit($config);
        if ($rateLimitError !== null) {
            return ['success' => false, 'action' => $action, 'message' => $rateLimitError, 'data' => []];
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $client = $this->client($config);
        $result = $client->sendVerifyCode(
            phone: $phone,
            code: $code,
            options: is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return [
            'success' => $result['success'] ?? false,
            'action' => $action,
            'message' => $result['message'] ?? '',
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleFetchSignsAction(string $action, array $config): array
    {
        $client = $this->client($config);
        $signs = $client->fetchSignNames();

        return [
            'success' => true,
            'action' => $action,
            'message' => $signs !== [] ? '获取签名列表成功' : '未获取到签名列表',
            'data' => ['signs' => $signs],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePhone(array $payload): ?string
    {
        $phone = trim((string) ($payload['phone'] ?? ''));

        if ($phone === '') {
            return '缺少必要参数：phone';
        }

        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) {
            return '手机号格式不正确';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkRateLimit(array $config): ?string
    {
        $enabled = filter_var($config['rate_limit_enabled'] ?? true, FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            return null;
        }

        $limit = (int) ($config['ip_minute_limit'] ?? 6);
        if ($limit <= 0) {
            return null;
        }

        /** @var Request|null $request */
        $request = app('request');
        $ip = $request instanceof Request ? $request->ip() : '127.0.0.1';

        /** @var RateLimiter $limiter */
        $limiter = app(RateLimiter::class);
        $key = 'sms-aliyun:'.$ip;

        if ($limiter->tooManyAttempts($key, $limit)) {
            return '验证码发送过于频繁，请稍后再试';
        }

        $limiter->hit($key, 60);

        return null;
    }

    private function client(array $config): AliyunSmsClient
    {
        return new AliyunSmsClient($config);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verificationCode(array $payload): string
    {
        $code = trim((string) ($payload['code'] ?? ''));

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : (string) random_int(100000, 999999);
    }
}
