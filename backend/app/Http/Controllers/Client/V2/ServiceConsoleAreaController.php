<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 服务自定义控制台区域（自定义 HTML / 自定义 tab）
 *
 * capabilities / tickets 走常规登录态；content / actions 供 iframe 使用
 * （iframe 无法携带 Authorization 头），通过短时效票据校验。
 */
class ServiceConsoleAreaController extends Controller
{
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
        return $this->success(
            $this->clientServiceConsoleService->createConsoleAreaTicketForUser($request->user(), (int) $service),
            '已生成访问凭证'
        );
    }

    public function content(Request $request, int $service)
    {
        $ticket = trim((string) $request->query('ticket', ''));
        $moduleKey = trim((string) $request->query('module', ''));

        try {
            $payload = $this->clientServiceConsoleService->getConsoleAreaContentForTicket($ticket, (int) $service, $moduleKey);
            $html = trim((string) ($payload['html'] ?? ''));

            if ($html === '') {
                throw new BusinessException('该功能页暂无可展示内容', 42200);
            }

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 自定义区域内容加载失败', [
                'service_id' => (int) $service,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return response($this->errorPage($exception->getMessage()), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    public function actions(Request $request, int $service)
    {
        $ticket = trim((string) ($request->query('ticket', '') ?: $request->input('ticket', '')));
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

        return response()->json($result);
    }

    private function errorPage(string $message): string
    {
        $message = e(trim($message) !== '' ? $message : '功能页加载失败，请刷新页面重试');

        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>加载失败</title></head>'
            .'<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
            .'background:#f7f8fa;color:#4b5563;">'
            .'<div style="max-width:30rem;margin:4rem auto;padding:2rem;background:#fff;border-radius:0.5rem;'
            .'box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;">'
            .'<div style="font-size:1.05rem;color:#1f2937;margin-bottom:.6rem;">功能页加载失败</div>'
            .'<div style="font-size:.875rem;line-height:1.7;">'.$message.'</div>'
            .'</div></body></html>';
    }
}
