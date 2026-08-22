<?php

namespace TuraIDC\Plugins\Gateways\AliPay\Lib;

use App\Constants\PaymentGatewayCode;
use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Services\System\GatewayLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlipayClient
{
    private string $appId;

    private string $privateKey;

    private string $alipayPublicKey;

    private string $gateway;

    private string $signType;

    private string $charset;

    private string $notifyUrl;

    private string $timeout;

    private bool $enabled;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
        $this->appId = $this->configString('app_id', 'alipay_app_id', config('alipay.app_id', ''));
        $this->privateKey = $this->configString('private_key', 'alipay_private_key', config('alipay.private_key', ''));
        $this->alipayPublicKey = $this->configString('alipay_public_key', 'alipay_public_key', config('alipay.alipay_public_key', ''));
        $this->gateway = trim((string) config('alipay.gateway', 'https://openapi.alipay.com/gateway.do'));
        $this->notifyUrl = $this->resolveNotifyUrl();
        $this->timeout = config('alipay.timeout', '30m');
        $this->signType = 'RSA2';
        $this->charset = 'utf-8';
        $this->enabled = $this->configBool('alipay_enabled', 'alipay_enabled', false);
    }

    /**
     * 解析支付宝异步通知回调 URL。
     *
     * 必须指向**后端 API** 地址：支付宝服务器直接 POST 到这里，路由
     * /api/v2/client/payment/alipay/notify 只存在于后端。
     *
     * 回退顺序：ALIPAY_NOTIFY_URL → APP_URL（后端自身基址）→ APP_FRONTEND_URL。
     *
     * 此前实现把 app.frontend_url 排在 app.url 之前，与本方法的注释、以及
     * RechargeGatewayFailureTest 的两条断言都相反（那两条测试因此长期失败）。
     * 按后端优先修正的理由：frontend_url 是控制台前端来源，本项目是前后端分离部署
     * （见 SeparatedDeploymentRoutesTest：API 主机不提供前端 SPA 入口），前端域名不保证
     * 反代 /api。发到前端域名的后果是**静默丢回调**——支付宝那边成功、本地永不入账；
     * 而 app.url 若填了内网地址，describePrecreateNotifyUrl() 会判为不可用并直接不带
     * notify_url，是可以从日志诊断出来的显式失败。两害相权取后者。
     *
     * frontend_url 保留为最后兜底，供确实由控制台域名统一反代 API 的部署使用。
     * 生产环境请显式配置 ALIPAY_NOTIFY_URL，不要依赖回退。
     */
    private function resolveNotifyUrl(): string
    {
        // 优先使用专门配置的回调 URL
        $notifyUrl = trim((string) config('alipay.notify_url', ''));
        if ($notifyUrl !== '') {
            return $notifyUrl;
        }

        // 回退到后端自身基址
        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/').'/api/v2/client/payment/alipay/notify';
        }

        return rtrim((string) config('app.frontend_url', ''), '/').'/api/v2/client/payment/alipay/notify';
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->appId !== '' && $this->privateKey !== '';
    }

    public function matchesAppId(?string $appId): bool
    {
        $expected = trim($this->appId);
        $actual = trim((string) $appId);

        return $expected === '' || ($actual !== '' && hash_equals($expected, $actual));
    }

    /**
     * 从 settings 表读取，带 60 秒 Redis 缓存。
     * 管理员更新支付宝配置后，可通过 Cache::forget('payment.alipay.settings') 立即生效。
     */
    private function setting(string $key, string $default = ''): string
    {
        $settings = Cache::remember('payment.alipay.settings', 60, function () {
            return [
                'alipay_app_id' => Setting::getValue('payment', 'alipay_app_id') ?? '',
                'alipay_private_key' => Setting::getValue('payment', 'alipay_private_key') ?? '',
                'alipay_public_key' => Setting::getValue('payment', 'alipay_public_key') ?? '',
                'alipay_enabled' => Setting::getValue('payment', 'alipay_enabled') ?? '',
            ];
        });

        $value = $settings[$key] ?? null;

        return ($value !== null && $value !== '') ? (string) $value : $default;
    }

    private function configString(string $configKey, ?string $settingKey, mixed $default = ''): string
    {
        $value = $this->config[$configKey] ?? null;
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        if ($settingKey !== null) {
            return $this->setting($settingKey, (string) $default);
        }

        return trim((string) $default);
    }

    private function configBool(string $configKey, ?string $settingKey, bool $default): bool
    {
        $value = $this->config[$configKey] ?? null;
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        if ($settingKey !== null) {
            $settingValue = Setting::getValue('payment', $settingKey);
            if ($settingValue !== null && $settingValue !== '') {
                return filter_var($settingValue, FILTER_VALIDATE_BOOL);
            }
        }

        return $default;
    }

    /**
     * 预下单（生成二维码链接）
     */
    public function precreate(string $outTradeNo, float $amount, string $subject, ?string $timeoutExpress = null): array
    {
        $bizContent = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => number_format($amount, 2, '.', ''),
            'subject' => $subject,
            'timeout_express' => $timeoutExpress ?: $this->timeout,
        ];

        $notifyUrlMeta = $this->describePrecreateNotifyUrl();
        $params = $this->buildRequestParams('alipay.trade.precreate', $bizContent);

        try {
            $result = $this->request($params);
        } catch (BusinessException $exception) {
            app(GatewayLogService::class)->recordFailure(
                gateway: PaymentGatewayCode::ALIPAY,
                action: 'precreate',
                errorMsg: $exception->getMessage(),
                outTradeNo: $outTradeNo,
                requestData: $bizContent,
                responseData: [],
            );
            throw $exception;
        }

        if (! $notifyUrlMeta['usable']) {
            Log::warning('[支付宝当面付] precreate 未附带异步回调地址，将依赖主动查询', [
                'out_trade_no' => $outTradeNo,
                'notify_url' => $notifyUrlMeta['notify_url'],
                'reason' => $notifyUrlMeta['reason'],
            ]);
        }

        Log::info('[支付宝当面付] precreate 响应', SensitiveDataSanitizer::sanitize([
            'out_trade_no' => $outTradeNo,
            'response' => $result,
        ]));

        $data = $result['alipay_trade_precreate_response'] ?? [];

        if (($data['code'] ?? '') !== '10000') {
            Log::error('[支付宝当面付] 预下单失败', SensitiveDataSanitizer::sanitize(['data' => $data]));
            app(GatewayLogService::class)->recordFailure(
                gateway: PaymentGatewayCode::ALIPAY,
                action: 'precreate',
                errorMsg: $data['sub_msg'] ?? $data['msg'] ?? '预下单失败',
                outTradeNo: $outTradeNo,
                requestData: $bizContent,
                responseData: $data,
            );
            throw new BusinessException('支付宝预下单失败，请稍后重试');
        }

        app(GatewayLogService::class)->recordSuccess(
            gateway: PaymentGatewayCode::ALIPAY,
            action: 'precreate',
            outTradeNo: $outTradeNo,
            requestData: $bizContent,
            responseData: $data,
        );

        return [
            'qr_code' => $data['qr_code'] ?? '',
            'out_trade_no' => $data['out_trade_no'] ?? $outTradeNo,
        ];
    }

    /**
     * 主动查询订单状态
     */
    public function query(string $outTradeNo): array
    {
        $bizContent = ['out_trade_no' => $outTradeNo];
        $params = $this->buildRequestParams('alipay.trade.query', $bizContent);
        $result = $this->request($params);

        $data = $result['alipay_trade_query_response'] ?? [];

        return [
            'trade_status' => $data['trade_status'] ?? '',
            'trade_no' => $data['trade_no'] ?? '',
            'out_trade_no' => $data['out_trade_no'] ?? $outTradeNo,
            'total_amount' => $data['total_amount'] ?? '0.00',
            'raw' => $data,
        ];
    }

    /**
     * 交易退款
     */
    public function refund(
        string $outTradeNo,
        float $refundAmount,
        string $refundReason = '',
        ?string $tradeNo = null,
        ?string $outRequestNo = null,
    ): array {
        $bizContent = [
            'out_trade_no' => $outTradeNo,
            'refund_amount' => number_format($refundAmount, 2, '.', ''),
        ];

        if ($tradeNo !== null && trim($tradeNo) !== '') {
            $bizContent['trade_no'] = trim($tradeNo);
        }

        if ($refundReason !== '') {
            $bizContent['refund_reason'] = $refundReason;
        }

        if ($outRequestNo !== null && trim($outRequestNo) !== '') {
            $bizContent['out_request_no'] = trim($outRequestNo);
        }

        $params = $this->buildRequestParams('alipay.trade.refund', $bizContent);

        try {
            $result = $this->request($params);
        } catch (BusinessException $exception) {
            app(GatewayLogService::class)->recordFailure(
                gateway: PaymentGatewayCode::ALIPAY,
                action: 'refund',
                errorMsg: $exception->getMessage(),
                outTradeNo: $outTradeNo,
                requestData: $bizContent,
                responseData: [],
            );
            throw $exception;
        }

        Log::info('[支付宝当面付] refund 响应', SensitiveDataSanitizer::sanitize([
            'out_trade_no' => $outTradeNo,
            'trade_no' => $tradeNo,
            'out_request_no' => $outRequestNo,
            'response' => $result,
        ]));

        $data = $result['alipay_trade_refund_response'] ?? [];

        if (($data['code'] ?? '') !== '10000') {
            Log::error('[支付宝当面付] 退款失败', SensitiveDataSanitizer::sanitize(['data' => $data]));
            app(GatewayLogService::class)->recordFailure(
                gateway: PaymentGatewayCode::ALIPAY,
                action: 'refund',
                errorMsg: $data['sub_msg'] ?? $data['msg'] ?? '退款失败',
                outTradeNo: $outTradeNo,
                requestData: $bizContent,
                responseData: $data,
            );
            throw new BusinessException('支付宝退款失败，请稍后重试');
        }

        app(GatewayLogService::class)->recordSuccess(
            gateway: PaymentGatewayCode::ALIPAY,
            action: 'refund',
            outTradeNo: $outTradeNo,
            tradeNo: $data['trade_no'] ?? $tradeNo,
            requestData: $bizContent,
            responseData: $data,
        );

        return [
            'trade_no' => $data['trade_no'] ?? ($tradeNo ?? ''),
            'out_trade_no' => $data['out_trade_no'] ?? $outTradeNo,
            'refund_fee' => $data['refund_fee'] ?? number_format($refundAmount, 2, '.', ''),
            'fund_change' => $data['fund_change'] ?? '',
            'gmt_refund_pay' => $data['gmt_refund_pay'] ?? '',
            'raw' => $data,
        ];
    }

    /**
     * 验证异步通知签名
     */
    public function verifyNotify(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        $signType = $params['sign_type'] ?? 'RSA2';

        unset($params['sign'], $params['sign_type']);
        ksort($params);

        // 缺少签名或未配置支付宝公钥时直接判定失败，避免 openssl_verify 抛出 PHP 警告导致 500。
        if ($sign === '' || trim((string) $this->alipayPublicKey) === '') {
            return false;
        }

        $stringToSign = urldecode(http_build_query($params));

        $publicKey = "-----BEGIN PUBLIC KEY-----\n"
            .wordwrap($this->alipayPublicKey, 64, "\n", true)
            ."\n-----END PUBLIC KEY-----";

        $publicKeyResource = openssl_pkey_get_public($publicKey);
        if ($publicKeyResource === false) {
            Log::warning('[支付宝回调] 公钥无效，签名验证失败');

            return false;
        }

        $algorithm = $signType === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($stringToSign, base64_decode($sign), $publicKeyResource, $algorithm) === 1;
    }

    /**
     * 发送请求到支付宝网关，自动处理 GBK→UTF-8 转码
     */
    private function request(array $params): array
    {
        // charset 必须放在 URL 查询字符串中，否则支付宝按 GBK 解码 POST body 导致中文签名不一致
        $url = $this->gateway.'?charset='.$this->charset;

        try {
            $response = $this->buildHttpClient()->post($url, $params);
        } catch (ConnectionException $exception) {
            Log::error('[支付宝当面付] 网关请求失败', [
                'gateway' => $this->gateway,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new BusinessException('支付网关暂时不可用，请稍后重试', 42200, 422);
        }

        $body = $response->body();

        // 支付宝网关始终返回 GBK 编码，需转为 UTF-8 才能 json_decode
        if (! json_decode($body)) {
            $body = mb_convert_encoding($body, 'UTF-8', 'GBK');
        }

        $result = json_decode($body, true);

        if (! is_array($result)) {
            Log::error('[支付宝当面付] 响应解析失败', [
                'http_status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return [];
        }

        return $result;
    }

    /**
     * 不设置 verify：项目硬规则要求插件不提供 SSL 与 CA 配置，统一依赖系统 CA
     * （Guzzle 默认 verify=true）。原实现可经插件配置或 config('alipay.ssl_verify') 关闭校验，
     * 而后者在 APP_ENV=local 时默认为 false——支付回调链路上留这条通路风险最高。
     */
    private function buildHttpClient(): PendingRequest
    {
        return Http::asForm()
            ->timeout(15)
            ->retry(1, 200);
    }

    /**
     * 构建请求公共参数
     */
    private function buildRequestParams(string $method, array $bizContent): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => $this->charset,
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ];

        if ($method === 'alipay.trade.precreate') {
            $notifyUrl = $this->resolvePrecreateNotifyUrl();
            if ($notifyUrl !== null) {
                $params['notify_url'] = $notifyUrl;
            }
        }

        $params['sign'] = $this->generateSign($params);

        return $params;
    }

    private function describePrecreateNotifyUrl(): array
    {
        $notifyUrl = trim($this->notifyUrl);

        if ($notifyUrl === '') {
            return [
                'usable' => false,
                'notify_url' => '',
                'reason' => 'empty',
            ];
        }

        $scheme = strtolower((string) parse_url($notifyUrl, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return [
                'usable' => false,
                'notify_url' => $notifyUrl,
                'reason' => 'unsupported_scheme',
            ];
        }

        $host = strtolower((string) parse_url($notifyUrl, PHP_URL_HOST));
        if ($host === '') {
            return [
                'usable' => false,
                'notify_url' => $notifyUrl,
                'reason' => 'invalid_host',
            ];
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return [
                'usable' => false,
                'notify_url' => $notifyUrl,
                'reason' => 'loopback_host',
            ];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [
                'usable' => false,
                'notify_url' => $notifyUrl,
                'reason' => 'private_or_reserved_ip',
            ];
        }

        return [
            'usable' => true,
            'notify_url' => $notifyUrl,
            'reason' => 'ok',
        ];
    }

    private function resolvePrecreateNotifyUrl(): ?string
    {
        $notifyUrlMeta = $this->describePrecreateNotifyUrl();

        return $notifyUrlMeta['usable'] ? $notifyUrlMeta['notify_url'] : null;
    }

    /**
     * RSA2 签名
     */
    private function generateSign(array $params): string
    {
        ksort($params);
        $stringToSign = urldecode(http_build_query($params));

        // 自动检测 PKCS8 / PKCS1 格式
        $key = $this->privateKey;
        if (str_starts_with($key, 'MIIEv')) {
            $pemKey = "-----BEGIN PRIVATE KEY-----\n"
                .wordwrap($key, 64, "\n", true)
                ."\n-----END PRIVATE KEY-----";
        } else {
            $pemKey = "-----BEGIN RSA PRIVATE KEY-----\n"
                .wordwrap($key, 64, "\n", true)
                ."\n-----END RSA PRIVATE KEY-----";
        }

        $algorithm = $this->signType === 'RSA2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        openssl_sign($stringToSign, $signature, $pemKey, $algorithm);

        return base64_encode($signature);
    }
}
