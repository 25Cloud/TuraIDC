<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\AliyunSms\Lib;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 阿里云「短信服务」HTTP 客户端 —— 完全自包含，不依赖内核驱动。
 *
 * 使用 V3 签名（ACS3-HMAC-SHA256）：请求参数走 query、签名放 Authorization 头、body 为空。
 * 这与同域 aliyun 插件用的 RPC V1.0 query 签名不同，两者不可混用。
 */
class AliyunSmsApiClient
{
    private const DEFAULT_ENDPOINT = 'dysmsapi.aliyuncs.com';

    private const API_VERSION = '2017-05-25';

    private const SIGNATURE_ALGORITHM = 'ACS3-HMAC-SHA256';

    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /** purpose → 场景模板的配置键。留空则回退到默认模板。 */
    private const SCENE_TEMPLATE_KEYS = [
        'login' => 'template_login',
        'register' => 'template_login',
        'generic' => 'template_login',
        'change_phone' => 'template_change_phone',
        'phone_change' => 'template_change_phone',
        'update_phone' => 'template_change_phone',
        'reset' => 'template_reset_password',
        'reset_password' => 'template_reset_password',
        'password_reset' => 'template_reset_password',
        'bind_phone' => 'template_bind_phone',
        'new_phone' => 'template_bind_phone',
        'verify_bound_phone' => 'template_verify_bound_phone',
        'verify_phone' => 'template_verify_bound_phone',
    ];

    /**
     * 阿里云错误码 → 用户可读文案。
     * 命中时直接透传给前端，避免被通用脱敏吞掉导致用户盲目重试。
     *
     * @var array<string, string>
     */
    private const FAILURE_CODE_MESSAGES = [
        'isv.BUSINESS_LIMIT_CONTROL' => '验证码发送过于频繁，请稍后再试',
        'isv.OUT_OF_SERVICE' => '短信服务已停机，请检查阿里云短信服务状态',
        'isv.AMOUNT_NOT_ENOUGH' => '短信账户余额不足，请充值后重试',
        'isv.MOBILE_NUMBER_ILLEGAL' => '手机号码格式错误',
        'isv.MOBILE_COUNT_OVER_LIMIT' => '单次发送号码数量超出限制',
        'isv.SMS_SIGNATURE_ILLEGAL' => '短信签名不合法，请核对插件配置中的签名名称',
        'isv.SMS_TEMPLATE_ILLEGAL' => '短信模板不合法，请核对模板 ID',
        'isv.SMS_SIGNATURE_NO_PASS' => '短信签名未通过审核',
        'isv.SMS_TEMPLATE_NO_PASS' => '短信模板未通过审核',
        'isv.BLACK_KEY_WORDS_LIMIT' => '短信内容包含敏感词，请联系管理员',
        'isv.TEMPLATE_MISSING_PARAMETERS' => '短信模板参数缺失：模板中的变量名与插件配置的「验证码变量名」不一致，请核对',
        'isv.TEMPLATE_PARAMS_ILLEGAL' => '短信模板参数不合法，请核对变量名与取值',
        'isv.DOMESTIC_NUMBER_NOT_SUPPORTED' => '暂不支持该号码段发送短信',
        'isv.DAY_LIMIT_CONTROL' => '该号码当日接收短信已达上限',
        'InvalidAccessKeyId.NotFound' => 'AccessKey ID 不存在，请核对凭据',
        'SignatureDoesNotMatch' => 'AccessKey Secret 不正确，签名校验失败',
        'Forbidden.RAM' => '当前 AccessKey 缺少短信发送权限，请为其授予 AliyunDysmsFullAccess',
        'Throttling.User' => '请求过于频繁，已被阿里云限流，请稍后重试',
    ];

    /**
     * @param  array<string, mixed>  $config  插件配置（来自 execute() 的 $request['config']）
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * 发送验证码短信。
     *
     * @param  array<string, mixed>  $options  purpose / min / sign_name 等
     * @return array<string, mixed>
     */
    public function sendVerifyCode(string $phone, string $code, array $options = []): array
    {
        $credentialError = $this->assertCredentials();
        if ($credentialError !== null) {
            return ['success' => false, 'message' => $credentialError];
        }

        $templateCode = $this->resolveTemplateCode($options);
        if ($templateCode === '') {
            return ['success' => false, 'message' => '未配置短信模板 ID，请在插件配置中填写默认模板 ID'];
        }

        $templateParams = [$this->codeVariable() => $code];

        // 有效期变量只在配置了变量名时才传：模板里没有这个变量却传了，阿里云会判参数不合法
        $expireVariable = $this->expireVariable();
        if ($expireVariable !== '') {
            $expireMinutes = trim((string) ($options['min'] ?? '5'));
            $templateParams[$expireVariable] = $expireMinutes !== '' ? $expireMinutes : '5';
        }

        $result = $this->sendSms($phone, $templateCode, $templateParams, $options);

        if ($result['success'] ?? false) {
            $result['template_code'] = $templateCode;
            $result['template_params'] = $templateParams;
        }

        return $result;
    }

    /**
     * 发送模板短信。
     *
     * 说明：系统层的模板短信通道（SmsService::sendTemplateSms）只把渲染好的正文交给驱动，
     * 并不透传模板变量；而阿里云短信服务只接受「模板 ID + 变量」，不接受正文。
     * 因此这条通道在本插件下仅适用于**不含变量**的阿里云模板；
     * 若模板需要变量，阿里云会返回 isv.TEMPLATE_MISSING_PARAMETERS，此时错误文案会说明原因。
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendMessage(string $phone, string $templateCode, string $content, array $options = []): array
    {
        $credentialError = $this->assertCredentials();
        if ($credentialError !== null) {
            return ['success' => false, 'message' => $credentialError];
        }

        $resolvedTemplate = trim($templateCode) !== '' ? trim($templateCode) : $this->defaultTemplateCode();
        if ($resolvedTemplate === '') {
            return ['success' => false, 'message' => '未指定阿里云短信模板 ID'];
        }

        // 调用方若显式给了模板变量就用它；系统模板通道走不到这里，留作扩展入口
        $templateParams = is_array($options['template_params'] ?? null) ? $options['template_params'] : [];

        return $this->sendSms($phone, $resolvedTemplate, $templateParams, $options);
    }

    /**
     * 查询模板，用于「检测」按钮核验模板 ID 是否存在及审核状态。
     *
     * 选它做健康检查是因为这是只读接口：不发短信、不计费、不占用发送额度，
     * 同时又能同时验证 AccessKey 有效性与模板可用性。
     *
     * @return array<string, mixed>
     */
    public function queryTemplate(string $templateCode): array
    {
        $credentialError = $this->assertCredentials();
        if ($credentialError !== null) {
            return ['success' => false, 'message' => $credentialError];
        }

        return $this->request('QuerySmsTemplate', ['TemplateCode' => $templateCode]);
    }

    /**
     * 查询已审核通过的签名列表。
     *
     * @return list<string>
     */
    public function fetchSignNames(): array
    {
        if ($this->assertCredentials() !== null) {
            return [];
        }

        $result = $this->request('QuerySmsSignList', ['PageIndex' => '1', 'PageSize' => '50']);
        if (! ($result['success'] ?? false)) {
            Log::warning('[短信] 阿里云短信服务获取签名列表失败', [
                'code' => (string) ($result['code'] ?? ''),
            ]);

            return [];
        }

        return $this->extractSignNames(is_array($result['raw'] ?? null) ? $result['raw'] : []);
    }

    /**
     * @param  array<string, string>  $templateParams
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sendSms(string $phone, string $templateCode, array $templateParams, array $options): array
    {
        $normalizedPhone = trim($phone);

        $query = [
            'PhoneNumbers' => $normalizedPhone,
            'SignName' => $this->resolveSignName($options),
            'TemplateCode' => $templateCode,
        ];

        if ($templateParams !== []) {
            $query['TemplateParam'] = (string) json_encode(
                $templateParams,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $result = $this->request('SendSms', $query, $normalizedPhone);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];

        return [
            'success' => true,
            'request_id' => isset($raw['RequestId']) ? (string) $raw['RequestId'] : null,
            'biz_id' => isset($raw['BizId']) ? (string) $raw['BizId'] : null,
            'raw' => $raw,
        ];
    }

    /**
     * 按 V3 签名（ACS3-HMAC-SHA256）发起请求。
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function request(string $action, array $query, string $phone = ''): array
    {
        $endpoint = $this->endpoint();
        $canonicalQuery = $this->buildCanonicalQuery($query);
        $contentSha256 = hash('sha256', '');

        $headers = [
            'host' => $endpoint,
            'x-acs-action' => $action,
            'x-acs-content-sha256' => $contentSha256,
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => bin2hex(random_bytes(16)),
            'x-acs-version' => self::API_VERSION,
        ];
        ksort($headers);

        $signedHeaders = implode(';', array_keys($headers));

        $canonicalHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= $key.':'.$value."\n";
        }

        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $contentSha256,
        ]);

        $stringToSign = self::SIGNATURE_ALGORITHM."\n".hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->accessKeySecret());

        $authorization = sprintf(
            '%s Credential=%s,SignedHeaders=%s,Signature=%s',
            self::SIGNATURE_ALGORITHM,
            $this->accessKeyId(),
            $signedHeaders,
            $signature
        );

        $logContext = ['action' => $action, 'endpoint' => $endpoint];
        if ($phone !== '') {
            // 按项目规范记录完整手机号：日志不做脱敏，否则发送异常时无法定位到具体号码
            $logContext['phone'] = $phone;
        }
        Log::info('[短信] 请求阿里云短信服务', $logContext);

        try {
            $response = Http::withHeaders(array_merge($headers, [
                'Authorization' => $authorization,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]))
                // 不设置 verify：项目硬规则要求插件不提供 SSL 与 CA 配置，统一依赖系统 CA
                ->timeout($this->requestTimeout())
                // body 必须为空：签名计算用的是空 body 的 sha256
                ->send('POST', 'https://'.$endpoint.'/?'.$canonicalQuery);
        } catch (\Throwable $exception) {
            Log::error('[短信] 阿里云短信服务请求异常', [
                'action' => $action,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return ['success' => false, 'message' => $this->resolveTransportMessage($exception)];
        }

        $data = $response->json();
        if (! is_array($data)) {
            Log::warning('[短信] 阿里云短信服务响应格式异常', [
                'action' => $action,
                'status' => $response->status(),
                'body' => SensitiveDataSanitizer::sanitizeText($response->body()),
            ]);

            return ['success' => false, 'message' => '短信服务响应异常，请稍后重试'];
        }

        $code = (string) ($data['Code'] ?? '');

        if ($code !== 'OK') {
            Log::warning('[短信] 阿里云短信服务返回失败', [
                'action' => $action,
                'status' => $response->status(),
                'code' => $code,
                'message' => (string) ($data['Message'] ?? ''),
            ]);

            return [
                'success' => false,
                'code' => $code,
                'message' => $this->resolveFailureMessage($data['Message'] ?? '', $code),
                'raw' => $data,
            ];
        }

        return ['success' => true, 'code' => $code, 'raw' => $data];
    }

    /**
     * 规范化查询串：键名升序，键值按 RFC3986 编码。
     *
     * PHP 的 rawurlencode 与阿里云要求一致——只保留 A-Za-z0-9-_.~ 不编码，
     * 空格编码为 %20（不是 urlencode 的 +）。
     *
     * @param  array<string, string>  $query
     */
    private function buildCanonicalQuery(array $query): string
    {
        ksort($query);

        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractSignNames(array $payload): array
    {
        $items = $payload['SmsSignList'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        if (array_key_exists('SignName', $items)) {
            $items = [$items];
        }

        $names = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $auditStatus = trim((string) ($item['AuditStatus'] ?? ''));
            if ($auditStatus !== '' && $auditStatus !== 'AUDIT_STATE_PASS') {
                continue;
            }

            $name = trim((string) ($item['SignName'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function assertCredentials(): ?string
    {
        if ($this->accessKeyId() === '' || $this->accessKeySecret() === '') {
            return '短信接口配置不完整：请填写 AccessKey ID 与 AccessKey Secret';
        }

        if ($this->resolveSignName([]) === '') {
            return '短信接口配置不完整：请填写短信签名';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveTemplateCode(array $options): string
    {
        $purpose = strtolower(trim((string) (
            $options['purpose']
            ?? $options['scene']
            ?? $options['type']
            ?? 'generic'
        )));

        $sceneKey = self::SCENE_TEMPLATE_KEYS[$purpose] ?? null;
        if ($sceneKey !== null) {
            $sceneTemplate = trim((string) ($this->config[$sceneKey] ?? ''));
            if ($sceneTemplate !== '') {
                return $sceneTemplate;
            }
        }

        return $this->defaultTemplateCode();
    }

    private function defaultTemplateCode(): string
    {
        return trim((string) ($this->config['template_code'] ?? ''));
    }

    private function codeVariable(): string
    {
        $variable = trim((string) ($this->config['code_variable'] ?? ''));

        return $variable !== '' ? $variable : 'code';
    }

    private function expireVariable(): string
    {
        return trim((string) ($this->config['expire_variable'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveSignName(array $options): string
    {
        $signName = trim((string) ($options['sign_name'] ?? ''));
        if ($signName !== '') {
            return $signName;
        }

        return trim((string) ($this->config['sign_name'] ?? ''));
    }

    private function accessKeyId(): string
    {
        return trim((string) ($this->config['access_key'] ?? ''));
    }

    private function accessKeySecret(): string
    {
        return trim((string) ($this->config['secret_key'] ?? ''));
    }

    private function endpoint(): string
    {
        $endpoint = trim((string) ($this->config['endpoint'] ?? ''));
        if ($endpoint === '') {
            return self::DEFAULT_ENDPOINT;
        }

        // 容错：管理员可能连协议或斜杠一起粘进来
        $endpoint = preg_replace('#^https?://#i', '', $endpoint) ?? $endpoint;

        return rtrim(trim($endpoint), '/') ?: self::DEFAULT_ENDPOINT;
    }

    private function requestTimeout(): int
    {
        $timeout = (int) ($this->config['request_timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout >= 3 && $timeout <= 30 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    private function resolveFailureMessage(mixed $message, string $code = ''): string
    {
        $code = trim($code);
        if ($code !== '' && isset(self::FAILURE_CODE_MESSAGES[$code])) {
            return self::FAILURE_CODE_MESSAGES[$code];
        }

        $text = trim((string) $message);
        if ($text === '') {
            return '短信发送失败，请稍后重试';
        }

        // 阿里云的英文原文对终端用户无意义，且可能泄露内部细节
        if (preg_match('/[a-z]{3,}|error|failed|exception|timeout|denied|invalid/i', $text) === 1) {
            return '短信发送失败，请稍后重试';
        }

        return $text;
    }

    private function resolveTransportMessage(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'certificate') || str_contains($message, 'ssl')) {
            // SMS_CA_BUNDLE 已随「插件不提供 SSL/CA 配置」一并移除，文案改为指向系统 CA
            return '短信接口 SSL 证书校验失败，请检查服务器系统 CA 证书是否过期';
        }

        if (str_contains($message, 'resolve host') || str_contains($message, 'could not resolve')) {
            return '短信接口域名解析失败，请检查服务器网络 DNS';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return '短信接口请求超时，请稍后重试';
        }

        return '短信接口请求失败，请检查服务器外网访问';
    }
}
