<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Certification\AlipayCertify\Logic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 支付宝身份认证（alipay.user.certify.open.*）客户端。
 *
 * 三步流程：
 *   1. initialize —— 提交姓名与证件号，换取 certify_id；
 *   2. certify    —— 页面接口，把签名后的参数拼成 GET URL 让用户跳转（扫码后在支付宝内刷脸）；
 *   3. query      —— 认证完成后回查结果，passed 为 T/F。
 * 另有异步通知（notify）：表单编码回调，需按 RSA2 验签。
 *
 * 签名要点（与 plugins/gateways/ali_pay 的支付网关同源，但此处做了两处收紧）：
 *   - 待签串按 key 升序、跳过空值与 sign 自身，用未编码的原始值以 k=v&k=v 拼接。
 *     支付网关用的 urldecode(http_build_query()) 在值本身含 & 或 = 时会拼错，这里不沿用。
 *   - 同步响应的验签对象是「响应节点的紧凑 JSON 原文」，不是排序后的参数串；
 *     必须从原始报文里截取该片段，json_decode 再 encode 会因转义与键序变化而验签失败。
 */
class AlipayCertifyClient
{
    private const DEFAULT_GATEWAY = 'https://openapi.alipay.com/gateway.do';

    private const CHARSET = 'utf-8';

    private const SIGN_TYPE = 'RSA2';

    private const API_VERSION = '1.0';

    private const SUCCESS_CODE = '10000';

    /**
     * 认证未完成时支付宝返回的 sub_code 片段。
     *
     * 这类响应的顶层 code 是 40004（业务处理失败），但语义是「用户还没做完」，
     * 必须映射为 pending 而不是 failed——判成 failed 会让用户看到「认证失败」并被要求重新发起。
     */
    private const NOT_FINISHED_SUB_CODES = [
        'CERTIFY_NOT_FINISH',
        'CERTIFY_NOT_EXIST',
        'CERTIFY_IN_PROCESS',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * 身份认证初始化：换取 certify_id。
     *
     * @return array<string, mixed> status 200 成功 / 400 业务失败 / 500 网络异常
     */
    public function initialize(string $realName, string $idCard, string $returnUrl): array
    {
        $missing = $this->missingCredentialLabels();
        if ($missing !== []) {
            return [
                'status' => 400,
                'message' => '请先在插件配置中填写'.implode('、', $missing),
            ];
        }

        $realName = trim($realName);
        $idCard = strtoupper(trim($idCard));
        if ($realName === '' || $idCard === '') {
            return ['status' => 400, 'message' => '请填写真实姓名与身份证号'];
        }

        $bizContent = [
            'outer_order_no' => $this->buildOuterOrderNo(),
            'biz_code' => $this->bizCode(),
            'identity_param' => [
                'identity_type' => 'CERT_INFO',
                'cert_type' => 'IDENTITY_CARD',
                'cert_name' => $realName,
                'cert_no' => $idCard,
            ],
        ];

        $returnUrl = trim($returnUrl);
        if ($returnUrl !== '') {
            $bizContent['merchant_config'] = ['return_url' => $returnUrl];
        }

        $result = $this->request('alipay.user.certify.open.initialize', $bizContent);
        if ($result === null) {
            return ['status' => 500, 'message' => $this->networkFailureMessage()];
        }

        if (! $this->isSuccess($result)) {
            return [
                'status' => 400,
                'message' => $this->providerMessage($result, '实名认证初始化失败'),
                'raw' => $result,
            ];
        }

        $certifyId = trim((string) ($result['certify_id'] ?? ''));
        if ($certifyId === '') {
            return [
                'status' => 400,
                'message' => '实名认证初始化失败：未返回认证标识',
                'raw' => $result,
            ];
        }

        return [
            'status' => 200,
            'message' => '实名认证初始化成功',
            'certify_id' => $certifyId,
            'raw' => $result,
        ];
    }

    /**
     * 开始认证：返回供用户跳转/扫码的页面地址。
     *
     * 这是页面接口，不发起服务端请求——只把签名后的公共参数拼成 GET URL。
     * 因此不会有网络失败，也不需要重试。
     *
     * @return array<string, mixed>
     */
    public function scanUrl(string $certifyId): array
    {
        $missing = $this->missingCredentialLabels();
        if ($missing !== []) {
            return [
                'status' => 400,
                'message' => '请先在插件配置中填写'.implode('、', $missing),
            ];
        }

        $certifyId = trim($certifyId);
        if ($certifyId === '') {
            return ['status' => 400, 'message' => '缺少认证标识，请重新发起实名认证'];
        }

        $params = $this->commonParams('alipay.user.certify.open.certify');
        $params['biz_content'] = $this->encodeBizContent(['certify_id' => $certifyId]);

        $returnUrl = trim((string) ($this->config['return_url'] ?? ''));
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }

        // 先用未编码的原始值签名，再对全部参数做 URL 编码拼接。顺序颠倒会导致验签失败。
        $params['sign'] = $this->sign($params);

        return [
            'status' => 200,
            'message' => '认证链接生成成功',
            'url' => $this->gateway().'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'raw' => ['certify_id' => $certifyId],
        ];
    }

    /**
     * 认证记录查询。
     *
     * @return array<string, mixed> status 1 通过 / 2 未通过 / 3 网络异常 / 4 处理中
     */
    public function queryStatus(string $certifyId): array
    {
        $missing = $this->missingCredentialLabels();
        if ($missing !== []) {
            return ['status' => 3, 'message' => '请先在插件配置中填写'.implode('、', $missing)];
        }

        $certifyId = trim($certifyId);
        if ($certifyId === '') {
            return ['status' => 3, 'message' => '缺少认证标识，无法查询认证结果'];
        }

        $result = $this->request('alipay.user.certify.open.query', ['certify_id' => $certifyId]);
        if ($result === null) {
            return ['status' => 3, 'message' => $this->networkFailureMessage()];
        }

        if (! $this->isSuccess($result)) {
            // 用户尚未走完认证：语义是「处理中」，不能判为失败
            if ($this->isNotFinished($result)) {
                return [
                    'status' => 4,
                    'message' => '认证尚未完成，请在支付宝中完成人脸核身',
                    'raw' => $result,
                ];
            }

            return [
                'status' => 2,
                'message' => $this->providerMessage($result, '实名认证未通过'),
                'raw' => $result,
            ];
        }

        $passed = strtoupper(trim((string) ($result['passed'] ?? '')));
        if ($passed === 'T') {
            return ['status' => 1, 'message' => '实名认证通过', 'raw' => $result];
        }

        if ($passed === 'F') {
            return [
                'status' => 2,
                'message' => $this->providerMessage($result, '实名认证未通过，请核对姓名与身份证号'),
                'raw' => $result,
            ];
        }

        // code=10000 但没给 passed：按处理中对待，交由后续轮询收敛
        return [
            'status' => 4,
            'message' => '认证结果尚未生成，请稍后重试',
            'raw' => $result,
        ];
    }

    /**
     * 校验异步通知签名。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verifyNotify(array $payload): array
    {
        $publicKey = $this->normalizePublicKey();
        if ($publicKey === null) {
            return [
                'passed' => false,
                'message' => '支付宝公钥未配置或格式不正确，无法校验回调签名',
                'code' => 40001,
                'http_status' => 401,
            ];
        }

        $sign = trim((string) ($payload['sign'] ?? ''));
        $certifyId = trim((string) ($payload['certify_id'] ?? ''));
        if ($sign === '') {
            return [
                'passed' => false,
                'message' => '回调缺少签名',
                'code' => 40001,
                'http_status' => 401,
            ];
        }

        // 缺业务标识就没有重放标识可建立：同一条通知能被无限次消费，
        // 而下面验签通过后会返回 passed=true。宁可拒收，也不能放一条无法去重的通知过去。
        if ($certifyId === '') {
            return [
                'passed' => false,
                'message' => '回调缺少认证标识，无法防重放',
                'code' => 40001,
                'http_status' => 401,
            ];
        }

        // 算法固定 SHA256，不读报文里的 sign_type：
        // 支付宝身份认证只签发 RSA2，若按报文取值，攻击者传 sign_type=RSA 即可把校验
        // 降级到 SHA1，用一份 SHA1 碰撞签名绕过验签。
        $algorithm = OPENSSL_ALGO_SHA256;

        // 异步通知的验签对象：剔除 sign 与 sign_type 后按 key 升序拼接
        $params = $payload;
        unset($params['sign'], $params['sign_type']);

        $verified = openssl_verify(
            $this->buildSignContent($params),
            base64_decode($sign, true) ?: '',
            $publicKey,
            $algorithm
        ) === 1;

        if (! $verified) {
            return [
                'passed' => false,
                'message' => '回调签名校验失败',
                'code' => 40001,
                'http_status' => 401,
            ];
        }

        return [
            'passed' => true,
            'message' => '回调签名校验通过',
            'code' => 0,
            'http_status' => 200,
            // 交给平台做重放拦截：同一 certify_id 的同一条签名只应被消费一次
            'replay_key' => 'alipay_certify:'.hash('sha256', $certifyId.'|'.$sign),
        ];
    }

    // ------------------------------------------------------------------ 内部实现

    /**
     * @param  array<string, mixed>  $bizContent
     * @return array<string, mixed>|null null 表示网络层失败（与业务失败区分，供上层映射不同状态）
     */
    private function request(string $method, array $bizContent): ?array
    {
        $params = $this->commonParams($method);
        $params['biz_content'] = $this->encodeBizContent($bizContent);
        $params['sign'] = $this->sign($params);

        try {
            // charset 同时放进查询串：支付宝网关否则按 GBK 解码 POST body，含中文时验签必然不一致
            $response = Http::asForm()
                ->timeout($this->requestTimeout())
                ->connectTimeout(10)
                ->post($this->gateway().'?charset='.self::CHARSET, $params);
        } catch (ConnectionException $exception) {
            Log::warning('[支付宝实名] 网关请求失败', [
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $body = $response->body();
        if (trim($body) === '') {
            Log::warning('[支付宝实名] 网关返回空响应', ['method' => $method, 'http_status' => $response->status()]);

            return null;
        }

        // 网关在少数配置下仍返回 GBK，先尝试直接解析，失败再转码
        if (json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
            $body = (string) mb_convert_encoding($body, 'UTF-8', 'GBK');
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            Log::warning('[支付宝实名] 响应解析失败', [
                'method' => $method,
                'http_status' => $response->status(),
                'body' => mb_substr($body, 0, 300),
            ]);

            return null;
        }

        $node = str_replace('.', '_', $method).'_response';
        $data = is_array($payload[$node] ?? null) ? $payload[$node] : [];

        // 同步响应验签：对象是响应节点在原始报文中的紧凑 JSON 原文。
        //
        // fail-closed：无法验签一律判失败，不存在放行分支。实名结果是授信数据
        // （passed=T 会被上层映射成 status=1「认证通过」并写进用户实名状态），
        // 原实现只在「片段截到了且公钥可用」时才验签，于是 normalizePublicKey() 因公钥
        // **格式错误**返回 null 时验签被静默跳过——入口的 missingCredentialLabels()
        // 只挡「未配置」，挡不住「配错」，攻击者只要能改动响应体就能伪造认证通过。
        // 上层把 null 映射为网络异常/处理中，配置问题另有下面这条 warning 可追。
        $sign = trim((string) ($payload['sign'] ?? ''));
        if ($sign !== '' && $data !== []) {
            $segment = $this->extractResponseSegment($body, $node);
            $publicKey = $this->normalizePublicKey();

            if ($segment === null || $publicKey === null) {
                Log::warning('[支付宝实名] 无法验签同步响应，按失败处理', [
                    'method' => $method,
                    'reason' => $publicKey === null ? 'invalid_public_key' : 'segment_not_found',
                ]);

                return null;
            }

            // 与异步通知同理：算法固定 SHA256，不接受报文侧把校验降级为 SHA1
            if (openssl_verify($segment, base64_decode($sign, true) ?: '', $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                Log::warning('[支付宝实名] 同步响应验签失败', ['method' => $method]);

                return null;
            }
        }

        return $data;
    }

    /**
     * 从原始报文中截取响应节点的 JSON 原文。
     *
     * 不能用 json_decode 再 json_encode：转义方式与键序都可能变化，验签必然失败。
     */
    private function extractResponseSegment(string $body, string $node): ?string
    {
        $needle = '"'.$node.'"';
        $start = strpos($body, $needle);
        if ($start === false) {
            return null;
        }

        $start = strpos($body, '{', $start + strlen($needle));
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($body);

        for ($i = $start; $i < $length; $i++) {
            $char = $body[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function commonParams(string $method): array
    {
        return [
            'app_id' => trim((string) ($this->config['app_id'] ?? '')),
            'method' => $method,
            'format' => 'JSON',
            'charset' => self::CHARSET,
            'sign_type' => self::SIGN_TYPE,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => self::API_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $bizContent
     */
    private function encodeBizContent(array $bizContent): string
    {
        return (string) json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function sign(array $params): string
    {
        $privateKey = $this->normalizePrivateKey();
        if ($privateKey === null) {
            return '';
        }

        $signature = '';
        openssl_sign($this->buildSignContent($params), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 构造待签串：按 key 升序，跳过 sign 与空值，用未编码的原始值以 k=v 拼接。
     *
     * @param  array<string, mixed>  $params
     */
    private function buildSignContent(array $params): string
    {
        $pairs = [];

        ksort($params);
        foreach ($params as $key => $value) {
            if ($key === 'sign') {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $text = (string) $value;
            if ($text === '') {
                continue;
            }

            $pairs[] = $key.'='.$text;
        }

        return implode('&', $pairs);
    }

    /**
     * 应用私钥归一为 PEM。支持 PKCS8（BEGIN PRIVATE KEY）与 PKCS1（BEGIN RSA PRIVATE KEY）。
     *
     * @return \OpenSSLAsymmetricKey|null
     */
    private function normalizePrivateKey(): mixed
    {
        $raw = trim((string) ($this->config['private_key'] ?? ''));
        if ($raw === '') {
            return null;
        }

        foreach ($this->candidatePemBlocks($raw, ['PRIVATE KEY', 'RSA PRIVATE KEY']) as $pem) {
            $key = openssl_pkey_get_private($pem);
            if ($key !== false) {
                return $key;
            }
        }

        Log::warning('[支付宝实名] 应用私钥格式不正确，无法签名');

        return null;
    }

    /**
     * @return \OpenSSLAsymmetricKey|null
     */
    private function normalizePublicKey(): mixed
    {
        $raw = trim((string) ($this->config['alipay_public_key'] ?? ''));
        if ($raw === '') {
            return null;
        }

        foreach ($this->candidatePemBlocks($raw, ['PUBLIC KEY']) as $pem) {
            $key = openssl_pkey_get_public($pem);
            if ($key !== false) {
                return $key;
            }
        }

        Log::warning('[支付宝实名] 支付宝公钥格式不正确，无法验签');

        return null;
    }

    /**
     * 管理员可能粘贴带 PEM 头尾的完整内容，也可能只粘中间的 base64。
     * 这里对两种形态都生成候选，逐一尝试加载。
     *
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    private function candidatePemBlocks(string $raw, array $labels): array
    {
        if (str_contains($raw, 'BEGIN')) {
            return [$raw];
        }

        $body = preg_replace('/\s+/', '', $raw) ?? '';
        $wrapped = chunk_split($body, 64, "\n");

        $candidates = [];
        foreach ($labels as $label) {
            $candidates[] = "-----BEGIN {$label}-----\n".$wrapped."-----END {$label}-----\n";
        }

        return $candidates;
    }

    /**
     * 未填写的必需凭据，用于给出可读的配置提示而不是笼统的签名失败。
     *
     * @return array<int, string>
     */
    private function missingCredentialLabels(): array
    {
        $missing = [];

        if (trim((string) ($this->config['app_id'] ?? '')) === '') {
            $missing[] = '应用 AppID';
        }

        if (trim((string) ($this->config['private_key'] ?? '')) === '') {
            $missing[] = '应用私钥';
        }

        if (trim((string) ($this->config['alipay_public_key'] ?? '')) === '') {
            $missing[] = '支付宝公钥';
        }

        return $missing;
    }

    /**
     * 商户请求号：支付宝要求 32 位以内的字母数字组合，且需保证唯一。
     */
    private function buildOuterOrderNo(): string
    {
        return substr('RN'.date('YmdHis').strtoupper(bin2hex(random_bytes(8))), 0, 32);
    }

    private function bizCode(): string
    {
        $allowed = ['FACE', 'SMART_FACE', 'CERT_PHOTO', 'CERT_PHOTO_FACE'];
        $code = strtoupper(trim((string) ($this->config['biz_code'] ?? '')));

        return in_array($code, $allowed, true) ? $code : 'FACE';
    }

    private function gateway(): string
    {
        $gateway = trim((string) ($this->config['gateway_url'] ?? ''));

        return $gateway !== '' ? rtrim($gateway, '?') : self::DEFAULT_GATEWAY;
    }

    private function requestTimeout(): int
    {
        $timeout = (int) ($this->config['request_timeout'] ?? 15);

        return max(5, min(60, $timeout));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isSuccess(array $result): bool
    {
        return trim((string) ($result['code'] ?? '')) === self::SUCCESS_CODE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isNotFinished(array $result): bool
    {
        $subCode = strtoupper(trim((string) ($result['sub_code'] ?? '')));
        if ($subCode === '') {
            return false;
        }

        foreach (self::NOT_FINISHED_SUB_CODES as $candidate) {
            if (str_contains($subCode, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取服务商文案，但过滤掉纯技术细节。
     *
     * 与同域其他插件一致：支付宝的 sub_msg 多为中文，出现连续英文字母（如
     * ISV.CERTIFY_NOT_FINISH）说明是错误码而非给终端用户看的说明，直接回退。
     *
     * @param  array<string, mixed>  $result
     */
    private function providerMessage(array $result, string $fallback): string
    {
        foreach (['sub_msg', 'msg'] as $key) {
            $text = trim((string) ($result[$key] ?? ''));
            if ($text === '' || preg_match('/[a-z]{3,}/i', $text) === 1) {
                continue;
            }

            return $text;
        }

        return $fallback;
    }

    private function networkFailureMessage(): string
    {
        return '支付宝实名接口暂时不可用，请稍后重试';
    }
}
