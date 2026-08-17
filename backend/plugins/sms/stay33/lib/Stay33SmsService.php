<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\Stay33\Lib;

class Stay33SmsService
{
    private ?Stay33SmsClient $client = null;

    public function key(): string
    {
        return 'stay33';
    }

    public function label(): string
    {
        return 'MC云短信';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        if ($action === 'sms.test') {
            return $this->send($action, $payload, $config, $this->verificationCode($payload));
        }

        if ($action === 'sms.send_message') {
            return $this->sendMessage($action, $payload, $config);
        }

        if ($action === 'sms.send_verify_code') {
            return $this->send($action, $payload, $config, trim((string) ($payload['code'] ?? '')));
        }

        return [
            'success' => false,
            'action' => $action,
            'message' => 'Unsupported plugin action',
            'data' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function send(string $action, array $payload, array $config, string $code): array
    {
        $phone = trim((string) ($payload['phone'] ?? ''));
        if ($phone === '') {
            return $this->failed($action, '缺少必要参数：phone');
        }

        if ($code === '') {
            return $this->failed($action, '缺少必要参数：code');
        }

        $result = $this->client($config)->sendVerifyCode(
            phone: $phone,
            code: $code,
            options: is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'action' => $action,
            'message' => (string) ($result['message'] ?? (($result['success'] ?? false) ? '短信发送成功' : '短信发送失败，请稍后重试')),
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sendMessage(string $action, array $payload, array $config): array
    {
        $phone = trim((string) ($payload['phone'] ?? ''));
        $templateCode = trim((string) ($payload['template_code'] ?? ''));
        $content = trim((string) ($payload['content'] ?? ''));

        if ($phone === '') {
            return $this->failed($action, '缺少必要参数：phone');
        }

        if ($content === '') {
            return $this->failed($action, '缺少必要参数：content');
        }

        $result = $this->client($config)->sendMessage(
            phone: $phone,
            templateCode: $templateCode,
            content: $content,
            options: is_array($payload['options'] ?? null) ? $payload['options'] : [],
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'action' => $action,
            'message' => (string) ($result['message'] ?? (($result['success'] ?? false) ? '短信发送成功' : '短信发送失败，请稍后重试')),
            'data' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $action, string $message): array
    {
        return [
            'success' => false,
            'action' => $action,
            'message' => $message,
            'data' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verificationCode(array $payload): string
    {
        $code = trim((string) ($payload['code'] ?? ''));

        return preg_match('/^\d{6}$/', $code) === 1 ? $code : (string) random_int(100000, 999999);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): Stay33SmsClient
    {
        if ($this->client === null) {
            $this->client = new Stay33SmsClient($config);
        }

        return $this->client;
    }
}
