<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\YiPay\Lib;

use App\Constants\PaymentGatewayCode;
use App\Exceptions\BusinessException;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\System\GatewayLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class YiPayClient
{
    private const PAYMENT_TYPE_LABELS = [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
    ];

    private const SIGN_TYPE_MD5 = 'MD5';

    private const SIGN_TYPE_RSA = 'RSA';

    private const SUPPORTED_SIGN_TYPES = [
        self::SIGN_TYPE_MD5,
        self::SIGN_TYPE_RSA,
    ];

    private string $merchantId;

    private string $merchantKey;

    private string $merchantPrivateKey;

    private string $platformPublicKey;

    private string $signType;

    /**
     * @var array<int, string>
     */
    private array $paymentTypes;

    private string $apiBaseUrl;

    private string $notifyUrl;

    private string $returnUrl;

    private string $siteName;

    private bool $enabled;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
        $this->merchantId = $this->configString('merchant_id');
        $this->merchantKey = $this->configString('merchant_key');
        $this->merchantPrivateKey = $this->configString('merchant_private_key');
        $this->platformPublicKey = $this->configString('platform_public_key');
        $this->signType = $this->normalizeSignType($this->configString('sign_type', self::SIGN_TYPE_MD5));
        $this->paymentTypes = $this->resolvePaymentTypes();
        $this->apiBaseUrl = $this->resolveApiBaseUrl();
        $this->notifyUrl = $this->resolveNotifyUrl();
        $this->returnUrl = $this->resolveReturnUrl();
        $this->siteName = $this->resolveSiteName();
        $this->enabled = $this->configBool('enabled', true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->merchantId !== ''
            && $this->hasSigningCredentials()
            && $this->apiBaseUrl !== ''
            && $this->notifyUrl !== ''
            && $this->returnUrl !== ''
            && $this->paymentTypes !== [];
    }

    public function matchesMerchantId(?string $merchantId): bool
    {
        $actual = trim((string) $merchantId);

        return $this->merchantId === '' || ($actual !== '' && hash_equals($this->merchantId, $actual));
    }

    /**
     * @return array<string, mixed>
     */
    public function precreate(string $outTradeNo, float $amount, string $subject, ?string $paymentType = null): array
    {
        $selectedPaymentType = $this->selectPaymentType($paymentType);
        $payload = [
            'pid' => $this->merchantId,
            'type' => $selectedPaymentType,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $this->notifyUrl,
            'return_url' => $this->returnUrl,
            'name' => mb_substr($subject !== '' ? $subject : $outTradeNo, 0, 100),
            'money' => number_format($amount, 2, '.', ''),
            'clientip' => $this->resolveClientIp(),
            'device' => 'pc',
            'param' => $outTradeNo,
            'sign_type' => $this->signType,
        ];

        if ($this->siteName !== '') {
            $payload['sitename'] = $this->siteName;
        }

        $payload['sign'] = $this->sign($payload);
        $result = $this->request('post', $this->endpoint('mapi.php'), $payload);

        if (! $this->isSuccessCode($result['code'] ?? null)) {
            $this->recordFailure('precreate', (string) ($result['msg'] ?? '预下单失败'), $outTradeNo, $payload, $result);
            throw new BusinessException('易支付预下单失败，请稍后重试');
        }

        $qrCode = $this->firstString($result, ['qrcode', 'img', 'payurl', 'urlscheme']);
        if ($qrCode === '') {
            $this->recordFailure('precreate', '预下单响应缺少支付链接', $outTradeNo, $payload, $result);
            throw new BusinessException('易支付预下单响应异常，请联系管理员处理');
        }

        $this->recordSuccess('precreate', $outTradeNo, $payload, $result);

        return [
            'qr_code' => $qrCode,
            'out_trade_no' => $outTradeNo,
            'raw' => $result,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function paymentOptions(): array
    {
        return collect($this->paymentTypes)
            ->map(fn (string $paymentType): array => [
                'key' => PaymentGatewayCode::YIPAY,
                'name' => '易支付 - '.self::PAYMENT_TYPE_LABELS[$paymentType],
                'label' => self::PAYMENT_TYPE_LABELS[$paymentType],
                'option_key' => PaymentGatewayCode::YIPAY.':'.$paymentType,
                'payment_type' => $paymentType,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $outTradeNo): array
    {
        $payload = [
            'act' => 'order',
            'pid' => $this->merchantId,
            'out_trade_no' => $outTradeNo,
        ];
        $payload = $this->withApiCredentials($payload);

        $result = $this->request('get', $this->endpoint('api.php'), $payload);

        if ((string) ($result['code'] ?? '') !== '1') {
            $this->recordFailure('query', (string) ($result['msg'] ?? '订单查询失败'), $outTradeNo, $payload, $result);
            throw new BusinessException('易支付订单查询失败，请稍后重试');
        }

        $tradeStatus = (int) ($result['status'] ?? 0) === 1 ? 'TRADE_SUCCESS' : 'WAIT_BUYER_PAY';

        return [
            'trade_status' => $tradeStatus,
            'trade_no' => (string) ($result['trade_no'] ?? ''),
            'out_trade_no' => (string) ($result['out_trade_no'] ?? $outTradeNo),
            'total_amount' => number_format((float) ($result['money'] ?? 0), 2, '.', ''),
            'raw' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(PaymentRefundRequest $request): array
    {
        $payload = [
            'act' => 'refund',
            'pid' => $this->merchantId,
            'out_trade_no' => $request->outTradeNo,
            'money' => number_format($request->refundAmount, 2, '.', ''),
        ];

        if ($request->tradeNo !== null && trim($request->tradeNo) !== '') {
            $payload['trade_no'] = trim($request->tradeNo);
        }
        $payload = $this->withApiCredentials($payload);

        $result = $this->request('post', $this->endpoint('api.php'), $payload);

        if ((string) ($result['code'] ?? '') !== '1') {
            $this->recordFailure('refund', (string) ($result['msg'] ?? '退款失败'), $request->outTradeNo, $payload, $result);
            throw new BusinessException('易支付退款失败，请稍后重试');
        }

        $this->recordSuccess('refund', $request->outTradeNo, $payload, $result);

        return [
            'trade_no' => (string) ($result['trade_no'] ?? $request->tradeNo ?? ''),
            'out_trade_no' => $request->outTradeNo,
            'refund_fee' => number_format($request->refundAmount, 2, '.', ''),
            'fund_change' => '',
            'gmt_refund_pay' => '',
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = trim((string) ($payload['sign'] ?? ''));
        $signType = $this->normalizeSignType((string) ($payload['sign_type'] ?? $this->signType));

        if ($sign === '') {
            return false;
        }

        if ($signType === self::SIGN_TYPE_MD5) {
            return $this->merchantKey !== '' && hash_equals(strtolower($sign), $this->sign($payload, self::SIGN_TYPE_MD5));
        }

        return $this->platformPublicKey !== '' && $this->verifyRsaSignature($payload, $sign);
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? null;

        return trim((string) (($value !== null && $value !== '') ? $value : $default));
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = $this->config[$key] ?? null;
        if ($value !== null && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return $default;
    }

    private function normalizeSignType(string $signType): string
    {
        $normalized = strtoupper(trim($signType));

        return in_array($normalized, self::SUPPORTED_SIGN_TYPES, true) ? $normalized : self::SIGN_TYPE_MD5;
    }

    private function hasSigningCredentials(): bool
    {
        if ($this->signType === self::SIGN_TYPE_MD5) {
            return $this->merchantKey !== '';
        }

        return $this->merchantPrivateKey !== '' && $this->platformPublicKey !== '';
    }

    /**
     * @return array<int, string>
     */
    private function resolvePaymentTypes(): array
    {
        if (array_key_exists('payment_types', $this->config)) {
            return $this->normalizePaymentTypes($this->config['payment_types']);
        }

        $legacyPaymentType = $this->normalizePaymentType($this->configString('payment_type', 'alipay'));

        return $legacyPaymentType !== null ? [$legacyPaymentType] : ['alipay'];
    }

    /**
     * @return array<int, string>
     */
    private function normalizePaymentTypes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): ?string => $this->normalizePaymentType((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function selectPaymentType(?string $paymentType): string
    {
        $requestedPaymentType = $this->normalizePaymentType((string) $paymentType);
        if ($requestedPaymentType !== null) {
            if (in_array($requestedPaymentType, $this->paymentTypes, true)) {
                return $requestedPaymentType;
            }

            throw new BusinessException('当前易支付未启用该支付方式');
        }

        $selectedPaymentType = $this->paymentTypes[0] ?? null;
        if ($selectedPaymentType !== null) {
            return $selectedPaymentType;
        }

        throw new BusinessException('当前易支付未配置可用支付方式');
    }

    private function normalizePaymentType(string $paymentType): ?string
    {
        $normalized = strtolower(trim($paymentType));

        return array_key_exists($normalized, self::PAYMENT_TYPE_LABELS) ? $normalized : null;
    }

    private function resolveNotifyUrl(): string
    {
        $baseUrl = trim((string) config('app.url', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/').'/api/v2/client/payment/notify/yipay' : '';
    }

    private function resolveReturnUrl(): string
    {
        $baseUrl = trim((string) config('app.client_console_url', ''));
        if ($baseUrl === '') {
            $baseUrl = trim((string) config('app.frontend_url', ''));
        }
        if ($baseUrl === '') {
            $baseUrl = trim((string) config('app.url', ''));
        }

        return $baseUrl !== '' ? rtrim($baseUrl, '/').'/client/recharge' : '';
    }

    private function resolveSiteName(): string
    {
        return mb_substr(trim((string) config('app.name', '图拉云')), 0, 50);
    }

    private function resolveApiBaseUrl(): string
    {
        $apiEndpoint = $this->configString('api_endpoint', $this->configString('api_base_url'));
        if ($apiEndpoint === '') {
            return '';
        }

        $parts = parse_url($apiEndpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        return rtrim($apiEndpoint, '/');
    }

    private function resolveClientIp(): string
    {
        try {
            $ip = request()?->ip();
        } catch (\Throwable) {
            $ip = null;
        }

        return trim((string) $ip) !== '' ? trim((string) $ip) : '127.0.0.1';
    }

    private function endpoint(string $path): string
    {
        if (preg_match('#/[^/]+\.php$#i', $this->apiBaseUrl) === 1) {
            return preg_replace('#/[^/]+$#', '/'.ltrim($path, '/'), $this->apiBaseUrl) ?: $this->apiBaseUrl;
        }

        return $this->apiBaseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload): array
    {
        try {
            $response = $method === 'get'
                ? $this->buildHttpClient()->get($url, $payload)
                : $this->buildHttpClient()->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::error('[易支付] 网关请求失败', [
                'url' => $url,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new BusinessException('易支付网关暂时不可用，请稍后重试', 42200, 422);
        }

        $result = $response->json();
        if (! is_array($result)) {
            $result = json_decode($response->body(), true);
        }

        if (! is_array($result)) {
            Log::error('[易支付] 响应解析失败', [
                'http_status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new BusinessException('易支付响应异常，请稍后重试', 42200, 422);
        }

        Log::info('[易支付] 网关响应', SensitiveDataSanitizer::sanitize([
            'url' => $url,
            'method' => $method,
            'out_trade_no' => (string) ($payload['out_trade_no'] ?? ''),
            'response' => $result,
        ]));

        return $result;
    }

    private function isSuccessCode(mixed $code): bool
    {
        return in_array((string) $code, ['1', '200'], true);
    }

    private function buildHttpClient(): PendingRequest
    {
        return Http::asForm()
            ->withOptions(['verify' => true])
            ->timeout(15)
            ->retry(1, 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withApiCredentials(array $payload): array
    {
        if ($this->merchantKey !== '') {
            $payload['key'] = $this->merchantKey;
        }

        if ($this->signType === self::SIGN_TYPE_RSA) {
            $payload['sign_type'] = $this->signType;
            $payload['sign'] = $this->sign($payload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload, ?string $signType = null): string
    {
        $selectedSignType = $this->normalizeSignType($signType ?? (string) ($payload['sign_type'] ?? $this->signType));
        $content = $this->canonicalString($payload);

        return $selectedSignType === self::SIGN_TYPE_RSA
            ? $this->signRsa($content)
            : md5($content.$this->merchantKey);
    }

    private function signRsa(string $content): string
    {
        $privateKey = openssl_pkey_get_private($this->normalizePrivateKey($this->merchantPrivateKey));
        if ($privateKey === false) {
            throw new BusinessException('易支付 RSA 商户私钥无效');
        }

        $signature = '';
        $signed = openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $signed) {
            throw new BusinessException('易支付 RSA 签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verifyRsaSignature(array $payload, string $sign): bool
    {
        $publicKey = openssl_pkey_get_public($this->normalizePublicKey($this->platformPublicKey));
        if ($publicKey === false) {
            return false;
        }

        $decodedSign = base64_decode($sign, true);
        if ($decodedSign === false) {
            return false;
        }

        return openssl_verify($this->canonicalString($payload), $decodedSign, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN PRIVATE KEY-----\n".chunk_split($key, 64, "\n").'-----END PRIVATE KEY-----';
    }

    private function normalizePublicKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n").'-----END PUBLIC KEY-----';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalString(array $payload): string
    {
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload, SORT_STRING);

        $pairs = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $pairs[] = $key.'='.(string) $value;
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    private function recordSuccess(string $action, string $outTradeNo, array $requestData, array $responseData): void
    {
        if (! Schema::hasTable('gateway_logs')) {
            return;
        }

        app(GatewayLogService::class)->recordSuccess(
            gateway: PaymentGatewayCode::YIPAY,
            action: $action,
            outTradeNo: $outTradeNo,
            tradeNo: (string) ($responseData['trade_no'] ?? ''),
            requestData: $this->sanitizeGatewayRequestData($requestData),
            responseData: $responseData,
        );
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    private function recordFailure(string $action, string $message, string $outTradeNo, array $requestData, array $responseData): void
    {
        if (! Schema::hasTable('gateway_logs')) {
            return;
        }

        app(GatewayLogService::class)->recordFailure(
            gateway: PaymentGatewayCode::YIPAY,
            action: $action,
            errorMsg: $message,
            outTradeNo: $outTradeNo,
            requestData: $this->sanitizeGatewayRequestData($requestData),
            responseData: $responseData,
        );
    }

    /**
     * 易支付查询/退款 API 的商户密钥字段名就是 key，通用脱敏器不会把普通 key
     * 全局视为敏感字段，这里在插件边界显式遮蔽。
     *
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function sanitizeGatewayRequestData(array $requestData): array
    {
        $sanitized = SensitiveDataSanitizer::sanitize($requestData);
        if (! is_array($sanitized)) {
            return [];
        }

        if (array_key_exists('key', $sanitized) && trim((string) $sanitized['key']) !== '') {
            $sanitized['key'] = '[REDACTED]';
        }

        return $sanitized;
    }
}
