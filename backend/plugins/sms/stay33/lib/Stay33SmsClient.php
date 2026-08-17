<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\Stay33\Lib;

use App\Support\SmsTemplateCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Stay33SmsClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendVerifyCode(string $phone, string $code, array $options = []): array
    {
        $phone = trim($phone);
        $code = trim($code);

        if ($phone === '' || $code === '') {
            return $this->failure('短信发送参数不完整');
        }

        $username = $this->configString('username');
        $apiKey = $this->configString('api_key', $this->configString('key'));
        if ($username === '' || $apiKey === '') {
            return $this->failure('短信接口配置不完整');
        }

        $signName = $this->optionString($options, 'sign_name', $this->configString('sign_name'));
        $templateCode = $this->optionString($options, 'template_code', $this->configString('template_code', SmsTemplateCatalog::TEMPLATE_VERIFY_CODE));
        $channel = $this->resolveChannel($options);
        if ($signName === '') {
            return $this->failure('短信接口配置不完整');
        }

        $content = $this->applySign($this->stripCodeMarkers($this->renderContent($code, $signName, $options)), $signName);
        $params = [
            'username' => $username,
            'key' => $apiKey,
            'phone' => $phone,
            'content' => $content,
            'channel' => $channel,
        ];

        $result = $this->request($params, $phone);
        if (! is_array($result)) {
            return $this->failure('短信接口请求失败，请稍后重试');
        }

        if ((int) ($result['code'] ?? 0) !== 1) {
            $message = $this->resolveFailureMessage($result['msg'] ?? '');
            Log::warning('[短信] MC云短信发送失败', [
                'code' => (int) ($result['code'] ?? 0),
                'message' => $message,
                'phone' => $this->maskPhone($phone),
            ]);

            return $this->failure($message, $result);
        }

        $successData = is_array($result['success_data'] ?? null) ? $result['success_data'] : [];
        $firstItem = is_array($successData[0] ?? null) ? $successData[0] : [];

        return [
            'success' => true,
            'status' => 'success',
            'request_id' => isset($firstItem['sms_code']) ? (string) $firstItem['sms_code'] : null,
            'template_code' => $templateCode,
            'template_params' => [
                'code' => $code,
                'sign_name' => $signName,
                'channel' => $channel,
            ],
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendMessage(string $phone, string $templateCode, string $content, array $options = []): array
    {
        $phone = trim($phone);
        $templateCode = trim($templateCode);
        $content = trim($content);

        if ($phone === '' || $content === '') {
            return $this->failure('短信发送参数不完整');
        }

        $username = $this->configString('username');
        $apiKey = $this->configString('api_key', $this->configString('key'));
        if ($username === '' || $apiKey === '') {
            return $this->failure('短信接口配置不完整');
        }

        $signName = $this->optionString($options, 'sign_name', $this->configString('sign_name'));
        if ($signName === '') {
            return $this->failure('短信接口配置不完整');
        }

        $channel = $this->resolveChannel($options);
        $content = $this->applySign($this->stripCodeMarkers($content), $signName);
        $params = [
            'username' => $username,
            'key' => $apiKey,
            'phone' => $phone,
            'content' => $content,
            'channel' => $channel,
        ];

        $result = $this->request($params, $phone);
        if (! is_array($result)) {
            return $this->failure('短信接口请求失败，请稍后重试');
        }

        if ((int) ($result['code'] ?? 0) !== 1) {
            $message = $this->resolveFailureMessage($result['msg'] ?? '');
            Log::warning('[短信] MC云短信发送失败', [
                'code' => (int) ($result['code'] ?? 0),
                'message' => $message,
                'phone' => $this->maskPhone($phone),
            ]);

            return $this->failure($message, $result);
        }

        $successData = is_array($result['success_data'] ?? null) ? $result['success_data'] : [];
        $firstItem = is_array($successData[0] ?? null) ? $successData[0] : [];

        return [
            'success' => true,
            'status' => 'success',
            'request_id' => isset($firstItem['sms_code']) ? (string) $firstItem['sms_code'] : null,
            'template_code' => $templateCode,
            'template_params' => [
                'content' => $content,
                'channel' => $channel,
            ],
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>|false
     */
    private function request(array $params, string $phone): array|false
    {
        foreach ($this->endpoints() as $endpoint) {
            try {
                $response = $this->http()
                    ->acceptJson()
                    ->timeout($this->timeout())
                    ->post($endpoint, $params);
            } catch (ConnectionException $exception) {
                Log::warning('[短信] MC云短信接口连接失败', [
                    'endpoint' => $endpoint,
                    'phone' => $this->maskPhone($phone),
                    'message' => $exception->getMessage(),
                ]);

                continue;
            } catch (\Throwable $exception) {
                Log::warning('[短信] MC云短信接口请求异常', [
                    'endpoint' => $endpoint,
                    'phone' => $this->maskPhone($phone),
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('[短信] MC云短信接口 HTTP 状态异常', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'phone' => $this->maskPhone($phone),
                ]);

                continue;
            }

            $json = $response->json();

            return is_array($json) ? $json : false;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function endpoints(): array
    {
        $endpoints = [
            $this->configString('api_endpoint', 'https://idc.stay33.cn/sms/sendApi.php'),
            $this->configString('backup_endpoint', 'https://api.freescdn.com/sms/sendApi.php'),
        ];

        return array_values(array_unique(array_filter($endpoints, static fn (string $endpoint): bool => $endpoint !== '')));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function renderContent(string $code, string $signName, array $options): string
    {
        $template = $this->optionString(
            $options,
            'template_content',
            $this->configString('template_content', '【{{sign_name}}】您的验证码是：{{code}}。')
        );

        $replacements = [
            'code' => $code,
            'sign_name' => $signName,
            'min' => '5',
            'expire_minutes' => '5',
        ];

        foreach ($replacements as $key => $value) {
            $template = str_replace('{{'.$key.'}}', $value, $template);
            $template = str_replace('{{ '.$key.' }}', $value, $template);
            $template = str_replace('{'.$key.'}', $value, $template);
        }

        return $template;
    }

    private function stripCodeMarkers(string $content): string
    {
        $content = preg_replace('/\{codes\}(.*?)\{\/codes\}/su', '$1', $content) ?? $content;

        return str_replace(['{codes}', '{/codes}'], '', $content);
    }

    private function applySign(string $content, string $signName): string
    {
        $content = trim($content);
        $normalizedSign = $this->normalizeSignName($signName);

        if ($normalizedSign === '' || preg_match('/^【[^】]+】/u', $content) === 1) {
            return $content;
        }

        return '【'.$normalizedSign.'】'.$content;
    }

    private function normalizeSignName(string $signName): string
    {
        return trim(str_replace(['【', '】'], '', $signName));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveChannel(array $options): string
    {
        $channel = $this->optionString($options, 'channel', $this->configString('channel', '1'));

        return $channel === '2' ? '2' : '1';
    }

    private function timeout(): int
    {
        $timeout = (int) ($this->config['timeout_seconds'] ?? 10);

        return max(1, min(60, $timeout));
    }

    private function http(): PendingRequest
    {
        return Http::asForm()->withOptions([
            'verify' => $this->sslVerifyOption(),
        ]);
    }

    private function sslVerifyOption(): bool|string
    {
        $caBundle = $this->resolveCaBundle();

        if ($this->resolveSslVerify() && $caBundle !== '' && is_file($caBundle)) {
            return $caBundle;
        }

        return $this->resolveSslVerify();
    }

    private function resolveSslVerify(): bool
    {
        $value = $this->config['ssl_verify'] ?? null;
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return filter_var(config('idc.sms.ssl_verify', true), FILTER_VALIDATE_BOOL);
    }

    private function resolveCaBundle(): string
    {
        $value = $this->config['ca_bundle'] ?? null;
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        return trim((string) config('idc.sms.ca_bundle', ''));
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? null;

        return trim((string) (($value !== null && $value !== '') ? $value : $default));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function optionString(array $options, string $key, string $default = ''): string
    {
        $value = $options[$key] ?? null;

        return trim((string) (($value !== null && $value !== '') ? $value : $default));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function failure(string $message, array $raw = []): array
    {
        return [
            'success' => false,
            'message' => $message !== '' ? $message : '短信发送失败，请稍后重试',
            'raw' => $raw,
        ];
    }

    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) <= 7) {
            return $phone;
        }

        return mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4);
    }

    private function resolveFailureMessage(mixed $message): string
    {
        $text = trim((string) $message);
        if ($text === '') {
            return '短信发送失败，请稍后重试';
        }

        if (preg_match('/sk_(live|test)|[a-z0-9]{24,}/i', $text) === 1) {
            return '短信发送失败，请稍后重试';
        }

        return mb_substr($text, 0, 120);
    }
}
