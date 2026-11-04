<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use TuraIDC\Plugins\Addons\AutoRefund\Logging\AutoRefundLogger;

/**
 * 上游智简魔方财务「开单接口」客户端（`api_ticket` 通道的 `zjfm` 协议）。
 *
 * 对应上游开源版 AutoRefund 的实际做法（`controller/clientarea/IndexController.php`
 * type=3 + `lib/ApiClient.php`），两步：
 *
 * 1. `POST {hostname}/v1/login_api`，表单 `username` + `api_key`；JWT 在响应**根级** `jwt`；
 * 2. `POST {hostname}/submitticket?step=2&dptid={部门}`，multipart 表单 + `Authorization: Bearer {jwt}`，
 *    字段 `title` / `content` / `host_id`。
 *
 * 成功判据是** 302 且 Location 含 `supporttickets`**（上游提交完会重定向到工单列表），
 * 不是 200 JSON；同时兼容某些变体直接回 `200 + {"code":200}`。
 * 其它一律失败，并把 HTTP 码与响应前 300 字回传（排错必须有原文，但不能整段落盘）。
 *
 * 纪律：
 * - 全部走 `Http::asForm()` / `Http::attach()`，不手搓 curl、不手拼 multipart 边界；
 * - 密钥与 JWT 只存在于请求头与请求体，**不进日志明文**（日志只记 `jwt_obtained` 布尔）；
 * - 地址或凭据缺任何一项都直接判未就绪，绝不允许带空密钥发单；
 * - `allow_redirects = false`：必须自己看到 302 与 Location，跟着跳转就看不到成功判据了。
 */
final class ZjfmTicketApiClient
{
    /** 上游 `code = 200` 的成功业务码（变体形态） */
    private const CODE_OK = 200;

    /** 失败回传里响应正文的截断长度 */
    private const BODY_LIMIT = 300;

    public function __construct(
        private readonly AutoRefundSettings $settings,
        private readonly AutoRefundLogger $logger,
    ) {}

    /**
     * 上游工单接口是否就绪（地址、账号、密钥、部门四项齐全）。
     */
    public function ready(): bool
    {
        return $this->settings->ticketProtocol() === AutoRefundSettings::TICKET_PROTOCOL_ZJFM
            && $this->settings->ticketChannelReady();
    }

    /**
     * 向上游提交一张工单。
     *
     * @return array{ok: bool, message: string, http_status: int|null, location: string|null, jwt_obtained: bool}
     */
    public function submit(string $title, string $content, string $upstreamHostId): array
    {
        if (! $this->ready()) {
            $missing = $this->settings->ticketChannelMissing();

            return $this->failure(
                '工单退款通道未就绪：'.($missing === [] ? '配置不完整' : '缺少 '.implode('、', $missing)),
                null,
                null,
            );
        }

        $host = $this->settings->ticketHostname();
        $jwt = $this->login($host);

        if ($jwt === null) {
            return $this->failure('上游工单接口登录失败，请检查用户名与密钥', null, null);
        }

        $url = $host.'/submitticket?step=2&dptid='.rawurlencode($this->settings->ticketDepartmentId());
        $timeout = $this->settings->requestTimeoutSeconds();

        try {
            $response = Http::withToken($jwt)
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10))
                ->attach('title', $title)
                ->attach('content', $content)
                ->attach('host_id', $upstreamHostId)
                ->post($url);
        } catch (ConnectionException $exception) {
            $this->logger->record('ticket_submit', 'unknown', [
                'host' => $host,
                'http_status' => null,
                'error' => $exception->getMessage(),
            ]);

            return $this->failure('上游工单接口连接超时或中断，本次结果未知', null, null, true);
        } catch (Throwable $exception) {
            $this->logger->record('ticket_submit', 'unknown', [
                'host' => $host,
                'http_status' => null,
                'error' => $exception->getMessage(),
            ]);

            return $this->failure('调用上游工单接口失败，本次结果未知', null, null, true);
        }

        $status = $response->status();
        $location = trim((string) $response->header('Location'));
        $bodySnippet = $this->bodySnippet($response);

        $this->logger->record('ticket_submit', $this->isSuccess($response, $location) ? 'ok' : 'failed', [
            'host' => $host,
            'http_status' => $status,
            'location' => $location,
            'body' => $bodySnippet,
        ]);

        if ($this->isSuccess($response, $location)) {
            return [
                'ok' => true,
                'message' => '上游已受理工单',
                'http_status' => $status,
                'location' => $location === '' ? null : $location,
                'jwt_obtained' => true,
            ];
        }

        return $this->failure(
            '上游工单提交失败（HTTP '.$status.'）'.($bodySnippet === '' ? '' : '：'.$bodySnippet),
            $status,
            $location === '' ? null : $location,
            true,
        );
    }

    /**
     * 登录换取 JWT。取不到就返回 null，绝不拿空串当令牌往下走。
     */
    private function login(string $host): ?string
    {
        $timeout = $this->settings->requestTimeoutSeconds();

        try {
            $response = Http::asForm()
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10))
                ->acceptJson()
                ->post($host.'/v1/login_api', [
                    'username' => $this->settings->ticketUsername(),
                    'api_key' => $this->settings->ticketApiKey(),
                ]);
        } catch (Throwable $exception) {
            $this->logger->record('ticket_login', 'failed', [
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        // 上游把 JWT 放在响应根级 `jwt`；没有就是没有，不去 data.jwt 里猜。
        $jwt = trim((string) ($response->json('jwt') ?? ''));

        $this->logger->record('ticket_login', $jwt === '' ? 'failed' : 'ok', [
            'host' => $host,
            'http_status' => $response->status(),
            'jwt_obtained' => $jwt !== '',
        ]);

        return $jwt === '' ? null : $jwt;
    }

    /**
     * 成功判据：302 且 Location 含 `supporttickets`；或变体形态 200 + JSON `code = 200`。
     */
    private function isSuccess(Response $response, string $location): bool
    {
        $status = $response->status();

        if ($status === 302 && str_contains($location, 'supporttickets')) {
            return true;
        }

        if ($status === self::CODE_OK) {
            $decoded = json_decode($response->body(), true);

            if (is_array($decoded) && (int) ($decoded['code'] ?? 0) === self::CODE_OK) {
                return true;
            }
        }

        return false;
    }

    private function bodySnippet(Response $response): string
    {
        $body = trim((string) $response->body());
        if ($body === '') {
            return '';
        }

        return mb_substr((string) preg_replace('/\s+/u', ' ', $body), 0, self::BODY_LIMIT);
    }

    /**
     * @return array{ok: bool, message: string, http_status: int|null, location: string|null, jwt_obtained: bool}
     */
    private function failure(string $message, ?int $httpStatus, ?string $location, bool $jwtObtained = false): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'http_status' => $httpStatus,
            'location' => $location,
            'jwt_obtained' => $jwtObtained,
        ];
    }
}
