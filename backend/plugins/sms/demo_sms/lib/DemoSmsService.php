<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\DemoSms\Lib;

use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\Data\SmsSendResult;
use App\Support\SmsTemplateCatalog;

class DemoSmsService
{
    public function key(): string
    {
        return 'demo_sms';
    }

    public function label(): string
    {
        return 'Demo 短信';
    }

    public function sendMessage(SmsMessageRequest $request): SmsSendResult
    {
        return new SmsSendResult(
            status: 'success',
            requestId: 'demo-sms-'.date('YmdHis'),
            templateCode: $request->templateCode,
            templateParams: ['content' => $request->content],
            raw: [
                'demo' => true,
                'phone' => $request->phone,
                'content' => $request->content,
            ],
        );
    }

    public function sendVerifyCode(SmsSendRequest $request): SmsSendResult
    {
        return new SmsSendResult(
            status: 'success',
            requestId: 'demo-sms-'.date('YmdHis'),
            templateCode: (string) $request->option('template_code', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE),
            templateParams: ['code' => $request->code],
            raw: ['demo' => true, 'phone' => $request->phone],
        );
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'sms.send_message' => $this->success($action, $this->sendMessage(new SmsMessageRequest(
                phone: (string) ($payload['phone'] ?? ''),
                templateCode: (string) ($payload['template_code'] ?? ''),
                content: (string) ($payload['content'] ?? ''),
                options: $this->resolveOptions($payload, $config),
            ))->toArray()),
            'sms.send_verify_code' => $this->success($action, $this->sendVerifyCode(new SmsSendRequest(
                phone: (string) ($payload['phone'] ?? ''),
                code: (string) ($payload['code'] ?? ''),
                options: $this->resolveOptions($payload, $config),
            ))->toArray()),
            'sms.test' => $this->success($action, $this->sendVerifyCode(new SmsSendRequest(
                phone: (string) ($payload['phone'] ?? ''),
                code: $this->verificationCode($payload),
                options: $this->resolveOptions($payload, $config),
            ))->toArray()),
            default => ['success' => false, 'action' => $action, 'message' => 'Unsupported plugin action', 'data' => []],
        };
    }

    private function success(string $action, array $data): array
    {
        return [
            'success' => true,
            'action' => $action,
            'data' => $data,
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveOptions(array $payload, array $config): array
    {
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        if (! array_key_exists('sign_name', $options) && isset($config['sign_name'])) {
            $options['sign_name'] = $config['sign_name'];
        }

        if (! array_key_exists('template_code', $options) && isset($config['template_code'])) {
            $options['template_code'] = $config['template_code'];
        }

        return $options;
    }
}
