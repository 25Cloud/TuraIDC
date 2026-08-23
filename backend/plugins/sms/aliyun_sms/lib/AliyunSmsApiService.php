<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Sms\AliyunSms\Lib;

use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * 阿里云「短信服务」插件服务层：动作派发、限流与健康检查。
 *
 * 对外动作与同域其他短信插件保持一致：
 * sms.send_verify_code / sms.send_message / sms.test / sms.fetch_signs / sms.verify_code_template
 */
class AliyunSmsApiService
{
    public function key(): string
    {
        return 'aliyun_sms';
    }

    public function label(): string
    {
        return '阿里云短信服务';
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

        return match ($action) {
            'sms.send_verify_code' => $this->handleSendVerifyCode($action, $payload, $config),
            'sms.test' => $this->handleTest($action, $payload, $config),
            'sms.send_message' => $this->handleSendMessage($action, $payload, $config),
            'sms.fetch_signs' => $this->handleFetchSigns($action, $config),
            'sms.verify_code_template' => [
                'success' => true,
                'action' => $action,
                'data' => ['template' => $this->verifyCodeTemplate($config)],
            ],
            default => [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ],
        };
    }

    /**
     * 「检测」按钮的落点：一次性核验凭据、签名与模板，全部只读、不发短信、不计费。
     *
     * 三步各自对应一类典型故障：
     * 1. QuerySmsTemplate → AccessKey 是否有效、模板 ID 是否存在、模板是否已过审；
     * 2. QuerySmsSignList → 配置的签名是否在「审核通过」列表里；
     * 3. 变量名比对     → 把模板正文里的 ${...} 占位符与配置的变量名对照，
     *    这一步能提前发现「模板用 ${captcha} 而配置填了 code」这类必然导致
     *    isv.TEMPLATE_MISSING_PARAMETERS 的错配，不必等到真实发送失败。
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $config = app(PluginConfigRepository::class)
            ->resolvedConfigByDomainAndSlug(PluginDomain::SMS, 'aliyun_sms');

        foreach ([
            'access_key' => 'AccessKey ID',
            'secret_key' => 'AccessKey Secret',
            'sign_name' => '短信签名',
            'template_code' => '默认模板 ID',
        ] as $key => $label) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return $this->unhealthy("未填写{$label}");
            }
        }

        $client = new AliyunSmsApiClient($config);
        $templateCode = trim((string) $config['template_code']);
        $signName = trim((string) $config['sign_name']);
        $codeVariable = trim((string) ($config['code_variable'] ?? 'code')) ?: 'code';
        $expireVariable = trim((string) ($config['expire_variable'] ?? ''));

        $templateResult = $client->queryTemplate($templateCode);
        if (! ($templateResult['success'] ?? false)) {
            return $this->unhealthy(
                (string) ($templateResult['message'] ?? '模板查询失败'),
                ['stage' => 'query_template', 'code' => $templateResult['code'] ?? null]
            );
        }

        $raw = is_array($templateResult['raw'] ?? null) ? $templateResult['raw'] : [];
        $templateStatus = $raw['TemplateStatus'] ?? null;
        $templateContent = (string) ($raw['TemplateContent'] ?? '');

        // TemplateStatus：0 审核中、1 审核通过、2 审核未通过
        if ($templateStatus !== null && (int) $templateStatus !== 1) {
            return $this->unhealthy(
                match ((int) $templateStatus) {
                    0 => "模板 {$templateCode} 正在审核中，暂时无法发送",
                    2 => "模板 {$templateCode} 审核未通过，请在阿里云控制台查看原因",
                    default => "模板 {$templateCode} 当前状态不可用（TemplateStatus={$templateStatus}）",
                },
                ['stage' => 'template_status', 'template_status' => $templateStatus]
            );
        }

        // 变量名比对：模板正文形如「您的验证码为${captcha}」
        $declaredVariables = [];
        if (preg_match_all('/\$\{\s*([A-Za-z0-9_]+)\s*\}/', $templateContent, $matches) === false) {
            $matches = [1 => []];
        }
        $declaredVariables = array_values(array_unique($matches[1] ?? []));

        if ($declaredVariables !== [] && ! in_array($codeVariable, $declaredVariables, true)) {
            return $this->unhealthy(
                sprintf(
                    '验证码变量名不匹配：模板 %s 中的变量为 %s，而插件配置填的是「%s」。请把「验证码变量名」改为模板里的名称。',
                    $templateCode,
                    implode('、', array_map(static fn (string $v): string => '${'.$v.'}', $declaredVariables)),
                    $codeVariable
                ),
                [
                    'stage' => 'variable_match',
                    'template_variables' => $declaredVariables,
                    'configured_code_variable' => $codeVariable,
                ]
            );
        }

        if ($expireVariable !== '' && $declaredVariables !== [] && ! in_array($expireVariable, $declaredVariables, true)) {
            return $this->unhealthy(
                sprintf(
                    '有效期变量名不匹配：模板 %s 中没有 ${%s}。模板不含有效期占位符时请把该项留空。',
                    $templateCode,
                    $expireVariable
                ),
                [
                    'stage' => 'variable_match',
                    'template_variables' => $declaredVariables,
                    'configured_expire_variable' => $expireVariable,
                ]
            );
        }

        // 模板里还有未被填充的变量：发送时同样会被阿里云判为参数缺失
        $providedVariables = array_values(array_filter([$codeVariable, $expireVariable !== '' ? $expireVariable : null]));
        $missingVariables = array_values(array_diff($declaredVariables, $providedVariables));
        if ($missingVariables !== []) {
            return $this->unhealthy(
                sprintf(
                    '模板 %s 还有未填充的变量：%s。本插件只能提供验证码与有效期两个变量，请改用仅含这两个变量的模板。',
                    $templateCode,
                    implode('、', array_map(static fn (string $v): string => '${'.$v.'}', $missingVariables))
                ),
                ['stage' => 'variable_match', 'missing_variables' => $missingVariables]
            );
        }

        // 签名核验：拿不到列表时不判失败（可能只是缺少查询权限），但要在结果里说明
        $signNames = $client->fetchSignNames();
        $signChecked = $signNames !== [];
        if ($signChecked && ! in_array($signName, $signNames, true)) {
            return $this->unhealthy(
                sprintf('短信签名「%s」不在已审核通过的签名列表中（可用：%s）', $signName, implode('、', $signNames)),
                ['stage' => 'sign_check', 'available_signs' => $signNames]
            );
        }

        return [
            'healthy' => true,
            'message' => '配置可用：凭据有效，模板已过审，变量名与模板匹配'
                .($signChecked ? '，签名已核验' : '（签名列表未取到，未做核验）'),
            'details' => [
                'template_code' => $templateCode,
                'template_content' => $templateContent,
                'template_variables' => $declaredVariables,
                'code_variable' => $codeVariable,
                'expire_variable' => $expireVariable !== '' ? $expireVariable : null,
                'sign_name' => $signName,
                'sign_checked' => $signChecked,
                'endpoint' => trim((string) ($config['endpoint'] ?? 'dysmsapi.aliyuncs.com')),
            ],
        ];
    }

    /**
     * 供系统侧展示/记账的验证码文案。
     *
     * 真实正文存放在阿里云控制台，本地并不掌握，因此这里按配置的变量名生成等价占位文案；
     * 需要看真实正文时用「检测」按钮，其 details.template_content 会回显阿里云侧的模板内容。
     *
     * @param  array<string, mixed>  $config
     */
    private function verifyCodeTemplate(array $config): string
    {
        $codeVariable = trim((string) ($config['code_variable'] ?? 'code')) ?: 'code';
        $expireVariable = trim((string) ($config['expire_variable'] ?? ''));

        $text = '您的验证码为${'.$codeVariable.'}，请注意保密，切勿告知他人。';
        if ($expireVariable !== '') {
            $text = '您的验证码为${'.$codeVariable.'}，${'.$expireVariable.'}分钟内有效，请注意保密，切勿告知他人。';
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleSendVerifyCode(string $action, array $payload, array $config): array
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

        $result = (new AliyunSmsApiClient($config))->sendVerifyCode(
            phone: trim((string) ($payload['phone'] ?? '')),
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
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleTest(string $action, array $payload, array $config): array
    {
        $phoneError = $this->validatePhone($payload);
        if ($phoneError !== null) {
            return ['success' => false, 'action' => $action, 'message' => $phoneError, 'data' => []];
        }

        $result = (new AliyunSmsApiClient($config))->sendVerifyCode(
            phone: trim((string) ($payload['phone'] ?? '')),
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
    private function handleSendMessage(string $action, array $payload, array $config): array
    {
        $result = (new AliyunSmsApiClient($config))->sendMessage(
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
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function handleFetchSigns(string $action, array $config): array
    {
        $signs = (new AliyunSmsApiClient($config))->fetchSignNames();

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
        if (! filter_var($config['rate_limit_enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
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
        $key = 'sms-aliyun-sms:'.$ip;

        if ($limiter->tooManyAttempts($key, $limit)) {
            return '验证码发送过于频繁，请稍后再试';
        }

        $limiter->hit($key, 60);

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verificationCode(array $payload): string
    {
        $code = trim((string) ($payload['code'] ?? ''));

        return preg_match('/^\d{4,8}$/', $code) === 1 ? $code : (string) random_int(100000, 999999);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function unhealthy(string $message, array $details = []): array
    {
        return [
            'healthy' => false,
            'message' => $message,
            'details' => $details,
        ];
    }
}
