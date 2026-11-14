<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AppendSecurityHeaders;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 服务自定义控制台区域（自定义 HTML / 自定义 tab）
 *
 * capabilities / tickets 走常规登录态；content / actions 供 iframe 使用，
 * 通过路径限定的短时效 HttpOnly Cookie 校验，Bearer 头保留给非 iframe 调用方。
 */
class ServiceConsoleAreaController extends Controller
{
    private const AREA_TICKET_COOKIE_PREFIX = 'tura_console_area_ticket_';

    private const AREA_TICKET_COOKIE_SAME_SITE = 'lax';

    public function __construct(
        private readonly ClientServiceConsoleService $clientServiceConsoleService,
    ) {}

    public function capabilities(Request $request, int $service)
    {
        return $this->success(
            $this->clientServiceConsoleService->getConsoleCapabilitiesForUser($request->user(), (int) $service)
        );
    }

    public function createTicket(Request $request, int $service)
    {
        $payload = $this->clientServiceConsoleService->createConsoleAreaTicketForUser($request->user(), (int) $service);
        $ticket = trim((string) ($payload['ticket'] ?? ''));

        return $this->success($payload, '已生成访问凭证')->withCookie(cookie(
            self::ticketCookieName((int) $service),
            $ticket,
            max(1, (int) ceil(((int) ($payload['expires_in'] ?? 180)) / 60)),
            self::ticketCookiePath((int) $service),
            null,
            $request->isSecure() || app()->environment('production'),
            true,
            true,
            self::AREA_TICKET_COOKIE_SAME_SITE
        ));
    }

    public function content(Request $request, int $service)
    {
        $ticket = $this->ticketFromRequest($request, (int) $service);
        $moduleKey = trim((string) $request->query('module', ''));

        try {
            $payload = $this->clientServiceConsoleService->getConsoleAreaContentForTicket($ticket, (int) $service, $moduleKey);
            $html = trim((string) ($payload['html'] ?? ''));

            if ($html === '') {
                throw new BusinessException('该功能页暂无可展示内容', 42200);
            }

            return response($html, 200, $this->panelResponseHeaders($html));
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 自定义区域内容加载失败', [
                'service_id' => (int) $service,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return response($this->errorPage($exception->getMessage()), 200, $this->panelResponseHeaders());
        }
    }

    public function actions(Request $request, int $service)
    {
        $ticket = $this->ticketFromRequest($request, (int) $service);
        $data = $request->except(['ticket', '_token']);

        try {
            $result = $this->clientServiceConsoleService->submitConsoleAreaActionForTicket($ticket, (int) $service, $data);
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 自定义区域动作提交失败', [
                'service_id' => (int) $service,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            $result = [
                'status' => 403,
                'msg' => '操作已过期或当前服务不可用，请刷新页面后重试',
                'data' => [],
            ];
        }

        return response()->json($result, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * 面板内引用的上游静态资源（CSS/JS/图片），经本系统反代 + 缓存后下发。
     *
     * 面板片段里的资源原本写的是上游绝对地址，直接下发会让终端用户看到上游域名；
     * 这里统一改走本端点——目标主机由服务绑定的供应商推导，调用方只能给「路径」，
     * 因此不可能被当成任意 URL 的开放反代。
     */
    public function asset(Request $request, int $service)
    {
        $path = $request->query('path');

        try {
            $asset = $this->clientServiceConsoleService->getConsoleAreaAssetForTicket(
                $this->ticketFromRequest($request, (int) $service),
                (int) $service,
                is_string($path) ? $path : ''
            );

            return response($asset['body'], 200, [
                'Content-Type' => $asset['content_type'],
                // 资源请求仍携带路径限定的 bearer cookie；禁止浏览器/代理把带凭据
                // 的响应写入共享缓存，避免跨用户复用或通过缓存日志泄漏上下文。
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 面板静态资源代理失败', [
                'service_id' => (int) $service,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return response('', 404, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }

    /**
     * 自定义功能面板响应头。
     *
     * 面板是上游渲染的 HTML，需在控制台域名下以 iframe 展示，因此：
     *   - 带 EMBEDDABLE_HEADER 标记，让 AppendSecurityHeaders 不再下发
     *     X-Frame-Options: SAMEORIGIN 与 frame-ancestors 'none'，否则浏览器直接
     *     判为「拒绝连接」，面板根本无法渲染；
     *   - frame-ancestors 只放行受信前端来源（与 CORS 同一份白名单配置）；
     *   - 上游脚本、事件属性和危险 URL 已在服务层清理；运行时脚本统一从本站静态
     *     资源加载，CSP 只允许本站脚本、样式和动作请求。
     *
     * @return array<string, string>
     */
    private function panelResponseHeaders(?string $html = null): array
    {
        $ancestors = ["'self'"];
        $styleSources = ["'self'"];

        if (is_string($html) && preg_match_all('~<style[^>]*>(?<css>.*?)</style>~is', $html, $matches) > 0) {
            foreach ($matches['css'] as $css) {
                $styleSources[] = "'sha256-".base64_encode(hash('sha256', (string) $css, true))."'";
            }
        }

        foreach ((array) config('cors.allowed_origins', []) as $origin) {
            $origin = trim((string) $origin);
            if ($origin !== '') {
                $ancestors[] = $origin;
            }
        }

        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            AppendSecurityHeaders::EMBEDDABLE_HEADER => '1',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "base-uri 'none'",
                "script-src 'self'",
                "script-src-attr 'none'",
                'style-src '.implode(' ', array_unique($styleSources)),
                "style-src-attr 'none'",
                "img-src 'self'",
                "font-src 'self'",
                "connect-src 'self'",
                "media-src 'self'",
                "form-action 'self'",
                "frame-src 'none'",
                "object-src 'none'",
                'frame-ancestors '.implode(' ', array_unique($ancestors)),
            ]),
        ];
    }

    private static function ticketCookieName(int $service): string
    {
        return self::AREA_TICKET_COOKIE_PREFIX.$service;
    }

    private static function ticketCookiePath(int $service): string
    {
        return '/api/v2/client/services/'.$service.'/console-area';
    }

    private function ticketFromRequest(Request $request, int $service): string
    {
        $bearer = trim((string) $request->bearerToken());
        if ($bearer !== '') {
            return $bearer;
        }

        return trim((string) $request->cookie(self::ticketCookieName($service), ''));
    }

    private function errorPage(string $message): string
    {
        $message = e(trim($message) !== '' ? $message : '功能页加载失败，请刷新页面重试');

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>加载失败</title></head><body>'
            .'<main><h1>功能页加载失败</h1><p>'.$message.'</p></main>'
            .'</body></html>';
    }
}
