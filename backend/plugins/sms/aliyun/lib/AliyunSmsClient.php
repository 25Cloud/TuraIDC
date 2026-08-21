<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\Aliyun\Lib;

use Illuminate\Support\Facades\Log;

/**
 * 阿里云短信 HTTP 客户端 — 完全自包含，不依赖内核驱动。
 */
class AliyunSmsClient
{
    private const VERIFY_ENDPOINT = 'https://dypnsapi.aliyuncs.com/';

    private const SMS_ENDPOINT = 'https://dysmsapi.aliyuncs.com/';

    private const DEFAULT_SIGN_NAME = '恒创联众';

    private const VERIFY_TEMPLATE_LOGIN_REGISTER = '100001';

    private const VERIFY_TEMPLATE_CHANGE_PHONE = '100002';

    private const VERIFY_TEMPLATE_RESET_PASSWORD = '100003';

    private const VERIFY_TEMPLATE_BIND_PHONE = '100004';

    private const VERIFY_TEMPLATE_VERIFY_BOUND_PHONE = '100005';

    private const VERIFY_TEMPLATE_CODES = [
        'login' => self::VERIFY_TEMPLATE_LOGIN_REGISTER,
        'register' => self::VERIFY_TEMPLATE_LOGIN_REGISTER,
        'generic' => self::VERIFY_TEMPLATE_LOGIN_REGISTER,
        'change_phone' => self::VERIFY_TEMPLATE_CHANGE_PHONE,
        'phone_change' => self::VERIFY_TEMPLATE_CHANGE_PHONE,
        'update_phone' => self::VERIFY_TEMPLATE_CHANGE_PHONE,
        'reset' => self::VERIFY_TEMPLATE_RESET_PASSWORD,
        'reset_password' => self::VERIFY_TEMPLATE_RESET_PASSWORD,
        'password_reset' => self::VERIFY_TEMPLATE_RESET_PASSWORD,
        'bind_phone' => self::VERIFY_TEMPLATE_BIND_PHONE,
        'new_phone' => self::VERIFY_TEMPLATE_BIND_PHONE,
        'verify_bound_phone' => self::VERIFY_TEMPLATE_VERIFY_BOUND_PHONE,
        'verify_phone' => self::VERIFY_TEMPLATE_VERIFY_BOUND_PHONE,
    ];

    /**
     * 阿里云错误码 → 用户可读文案。
     * 命中时直接透传给前端，避免被通用脱敏吞掉导致用户盲目重试。
     *
     * @var array<string, string>
     */
    private const FAILURE_CODE_MESSAGES = [
        'biz.FREQUENCY' => '验证码发送过于频繁，请稍后再试',
        'isv.BUSINESS_LIMIT_CONTROL' => '验证码发送过于频繁，请稍后再试',
        'isv.OUT_OF_SERVICE' => '短信服务暂不可用，请稍后再试',
        'isv.AMOUNT_NOT_ENOUGH' => '短信账户余额不足，请充值后重试',
        'isv.MOBILE_NUMBER_ILLEGAL' => '手机号码格式错误',
        'isv.SMS_SIGNATURE_ILLEGAL' => '短信签名不合法',
        'isv.SMS_TEMPLATE_ILLEGAL' => '短信模板不合法',
        'isv.SMS_SIGNATURE_NO_PASS' => '短信签名未通过审核',
        'isv.SMS_TEMPLATE_NO_PASS' => '短信模板未通过审核',
        'isv.BLACK_KEY_WORDS_LIMIT' => '短信内容包含敏感词，请联系管理员',
        'isv.TEMPLATE_MISSING_PARAMETERS' => '短信模板参数缺失，请联系管理员',
        'isv.DOMESTIC_NUMBER_NOT_SUPPORTED' => '暂不支持该号码段发送短信',
    ];

    /**
     * @param  array<string, mixed>  $config  插件配置（来自 execute() 的 $request['config']）
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @var array{errno: int, error: string}|null
     */
    private ?array $lastCurlError = null;

    /**
     * @return array{success: bool, request_id?: string, raw?: array}
     */
    public function sendVerifyCode(string $phone, string $code, array $options = []): array
    {
        $signName = $this->resolveSignName($options);
        $templateCode = $this->resolveVerifyTemplateCode($options);
        $expireMinutes = trim((string) ($options['min'] ?? '5'));
        $templateParams = ['code' => $code, 'min' => $expireMinutes !== '' ? $expireMinutes : '5'];

        $accessKeyId = (string) ($this->config['access_key'] ?? '');
        $accessKeySecret = (string) ($this->config['secret_key'] ?? '');

        if ($accessKeyId === '' || $accessKeySecret === '') {
            return ['success' => false, 'message' => '短信接口配置不完整'];
        }

        $params = [
            'Action' => 'SendSmsVerifyCode',
            'SchemeName' => '默认方案',
            'CountryCode' => '86',
            'PhoneNumber' => trim($phone),
            'SignName' => $signName,
            'TemplateCode' => $templateCode,
            'TemplateParam' => json_encode($templateParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CodeLength' => 6,
            'ValidTime' => 300,
            'CodeType' => 1,
            'AutoRetry' => $this->resolveAutoRetry($options),
        ];

        $result = $this->request(self::VERIFY_ENDPOINT, $params, $accessKeyId, $accessKeySecret, $phone);

        if (! is_array($result)) {
            return [
                'success' => false,
                'message' => $this->resolveCurlFailureMessage(),
                'raw' => $this->lastCurlError ?? [],
            ];
        }

        if (($result['Code'] ?? '') !== 'OK' || ($result['Success'] ?? false) !== true) {
            $failureCode = (string) ($result['Code'] ?? '');
            Log::warning('[短信] 阿里云短信发送失败', [
                'code' => $failureCode,
                'message' => $this->resolveFailureMessage($result['Message'] ?? '', $failureCode),
            ]);

            return ['success' => false, 'message' => $this->resolveFailureMessage($result['Message'] ?? '', $failureCode)];
        }

        $model = is_array($result['Model'] ?? null) ? $result['Model'] : [];

        return [
            'success' => true,
            'request_id' => isset($result['RequestId']) ? (string) $result['RequestId'] : null,
            'biz_id' => isset($model['BizId']) ? (string) $model['BizId'] : null,
            'template_code' => $templateCode,
            'template_params' => $templateParams,
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, request_id?: string, raw?: array}
     */
    public function sendMessage(string $phone, string $templateCode, string $content, array $options = []): array
    {
        return [
            'success' => false,
            'message' => '阿里云号码认证插件仅支持内置验证码模板，不支持系统短信模板正文发送；如需发送自定义模板短信请改用「阿里云短信服务」插件',
        ];
    }

    private function request(string $url, array $params, string $accessKeyId, string $accessKeySecret, string $phone = ''): array|false
    {
        $this->lastCurlError = null;

        $fixedParams = [
            'Format' => 'json',
            'RegionId' => 'cn-hangzhou',
            'SignatureMethod' => 'HMAC-SHA256',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
            'AccessKeyId' => $accessKeyId,
        ];

        $apiParams = array_merge($fixedParams, $params);
        ksort($apiParams);

        $sortedQuery = '';
        foreach ($apiParams as $key => $value) {
            $sortedQuery .= '&'.$this->encode($key).'='.$this->encode((string) $value);
        }

        $method = 'POST';
        $stringToSign = $method.'&%2F&'.$this->encode(substr($sortedQuery, 1));
        $sign = base64_encode(hash_hmac('sha256', $stringToSign, $accessKeySecret.'&', true));
        $body = 'Signature='.$this->encode($sign).$sortedQuery;

        $logContext = [
            'url' => $url,
            'action' => $params['Action'] ?? null,
        ];
        if ($phone !== '') {
            $logContext['phone'] = $this->maskPhone($phone);
        }

        Log::info('[短信] 请求阿里云短信接口', $logContext);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-sdk-client: php/2.0.0']);
        $this->configureCurlSsl($ch);

        try {
            $result = curl_exec($ch);
        } finally {
            curl_close($ch);
        }
        if ($result === false) {
            $this->lastCurlError = [
                'errno' => curl_errno($ch),
                'error' => curl_error($ch),
            ];
            Log::error('[短信] CURL 请求失败', [
                'errno' => $this->lastCurlError['errno'],
                'error' => $this->lastCurlError['error'],
                'ssl_verify' => $this->resolveSslVerify(),
                'has_ca_bundle' => $this->resolveCaBundle() !== '',
            ]);

            return false;
        }

        return json_decode($result, true) ?: false;
    }

    private function configureCurlSsl($ch): void
    {
        $sslVerify = $this->resolveSslVerify();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

        $caBundle = $this->resolveCaBundle();
        if ($sslVerify && $caBundle !== '' && is_file($caBundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
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

    private function encode(string $value): string
    {
        $encoded = urlencode($value);
        $encoded = str_replace('+', '%20', $encoded);
        $encoded = str_replace('*', '%2A', $encoded);

        return str_replace('%7E', '~', $encoded);
    }

    private function resolveVerifyTemplateCode(array $options): string
    {
        $purpose = strtolower(trim((string) (
            $options['purpose']
            ?? $options['scene']
            ?? $options['type']
            ?? 'generic'
        )));

        return self::VERIFY_TEMPLATE_CODES[$purpose] ?? self::VERIFY_TEMPLATE_LOGIN_REGISTER;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveAutoRetry(array $options): int
    {
        if (array_key_exists('auto_retry', $options)) {
            return filter_var($options['auto_retry'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveSignName(array $options): string
    {
        $signName = trim((string) ($options['sign_name'] ?? $this->config['sign_name'] ?? ''));

        return $signName !== '' ? $signName : self::DEFAULT_SIGN_NAME;
    }

    /**
     * @return list<string>
     */
    public function fetchSignNames(): array
    {
        $accessKeyId = (string) ($this->config['access_key'] ?? '');
        $accessKeySecret = (string) ($this->config['secret_key'] ?? '');

        if ($accessKeyId === '' || $accessKeySecret === '') {
            return [];
        }

        $result = $this->request(self::SMS_ENDPOINT, [
            'Action' => 'QuerySmsSignList',
            'PageIndex' => 1,
            'PageSize' => 50,
        ], $accessKeyId, $accessKeySecret);

        if (! is_array($result) || ($result['Code'] ?? '') !== 'OK') {
            Log::warning('[短信] 获取签名列表失败', [
                'code' => (string) ($result['Code'] ?? ''),
            ]);

            return [];
        }

        return $this->extractSignNames($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractSignNames(array $payload): array
    {
        $items = $payload['SmsSignList']['SmsSign']
            ?? $payload['SmsSignList']['Sign']
            ?? $payload['SmsSignList']
            ?? $payload['SmsSign']
            ?? [];

        if (! is_array($items)) {
            return [];
        }

        if (array_key_exists('SignName', $items) || array_key_exists('sign_name', $items)) {
            $items = [$items];
        }

        $names = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $auditStatus = trim((string) ($item['AuditStatus'] ?? $item['audit_status'] ?? ''));
            if ($auditStatus !== '' && $auditStatus !== 'AUDIT_STATE_PASS') {
                continue;
            }

            $name = trim((string) ($item['SignName'] ?? $item['sign_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) <= 7) {
            return $phone;
        }

        return mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4);
    }

    private function resolveFailureMessage(mixed $message, string $code = ''): string
    {
        // 已知错误码优先映射为用户可读文案（如 biz.FREQUENCY 频繁限制）
        $code = trim($code);
        if ($code !== '' && isset(self::FAILURE_CODE_MESSAGES[$code])) {
            return self::FAILURE_CODE_MESSAGES[$code];
        }

        $text = trim((string) $message);
        if ($text === '') {
            return '短信发送失败，请稍后重试';
        }

        if (preg_match('/[a-z]{3,}|error|failed|exception|timeout|denied|invalid/i', $text) === 1) {
            return '短信发送失败，请稍后重试';
        }

        return $text;
    }

    private function resolveCurlFailureMessage(): string
    {
        $errno = (int) ($this->lastCurlError['errno'] ?? 0);
        $error = strtolower((string) ($this->lastCurlError['error'] ?? ''));

        if ($errno === 60 || str_contains($error, 'certificate')) {
            return '短信接口 SSL 证书校验失败，请检查服务器 CA 证书或 SMS_CA_BUNDLE 配置';
        }

        if ($errno === 6 || str_contains($error, 'resolve host')) {
            return '短信接口域名解析失败，请检查服务器网络 DNS';
        }

        if ($errno === 7 || str_contains($error, 'connect')) {
            return '短信接口连接失败，请检查服务器外网访问';
        }

        if ($errno === 28 || str_contains($error, 'timeout')) {
            return '短信接口请求超时，请稍后重试';
        }

        return '短信接口请求失败，请稍后重试';
    }
}
